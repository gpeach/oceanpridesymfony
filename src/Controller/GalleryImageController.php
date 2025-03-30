<?php

namespace App\Controller;

use App\Entity\GalleryImage;
use App\Service\CloudStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class GalleryImageController extends AbstractController
{
    private bool $DEBUG_UPLOAD = true;

    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $log,
        private CloudStorageInterface $cloudStorage,
        private CacheInterface $cache
    ) {
    }

    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[Route('/gallery/upload', name: 'gallery_upload')]
    public function upload(Request $request): Response
    {
        $galleryImage = new GalleryImage();

        $form = $this->createFormBuilder($galleryImage)
            ->add('name', TextType::class)
            ->add('file', FileType::class, [
                'mapped' => false,
                'required' => true,
            ])
            ->getForm();

        $form->handleRequest($request);

        $this->log('Form handled');
        $this->log('Form submitted: ' . ($form->isSubmitted() ? 'yes' : 'no'));

        if ($form->isSubmitted()) {
            $this->log('Form is valid: ' . ($form->isValid() ? 'yes' : 'no'));

            if (!$form->isValid()) {
                foreach ($form->getErrors(true) as $error) {
                    $this->log('Form error: ' . $error->getMessage());
                }
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $file = $form->get('file')->getData();

            if ($file) {
                $filename = uniqid() . '.' . $file->guessExtension();
                $cloudPath = '/client_photos/' . $filename;

                try {
                    if ($this->DEBUG_UPLOAD) {
                        $this->log('Calling cloudStorage->upload()');
                    }

                    $this->cloudStorage->upload($cloudPath, $file->getPathname());

                    $galleryImage->setFilePath($cloudPath);
                    $galleryImage->setName($form->get('name')->getData());

                    $mime = $file->getMimeType();
                    if (str_starts_with($mime, 'image/')) {
                        $galleryImage->setType('image');
                    } elseif (str_starts_with($mime, 'video/')) {
                        $galleryImage->setType('video');
                    }

                    $cloudStorageType = $_ENV['CLOUD_STORAGE_DRIVER'] ?? 's3';
                    $galleryImage->setCloudStorageType($cloudStorageType);

                    // Cache the poster image
                    $posterImagePath = $this->cache->get(
                        'poster_image_' . $galleryImage->getId(),
                        function (ItemInterface $item) use ($galleryImage) {
                            $item->expiresAfter(3600);
                            return $this->generatePosterImage($galleryImage);
                        }
                    );
                    $galleryImage->setPosterImagePath($posterImagePath);

                    $this->em->persist($galleryImage);
                    $this->em->flush();

                    if ($this->DEBUG_UPLOAD) {
                        $this->log('Saved to DB with ID: ' . $galleryImage->getId());
                    }

                    return $this->redirectToRoute('gallery_index');
                } catch (\Throwable $e) {
                    $this->addFlash('error', 'Upload failed: ' . $e->getMessage());
                    if ($this->DEBUG_UPLOAD) {
                        $this->log('Exception: ' . $e->getMessage());
                    }
                    return $this->redirectToRoute('gallery_upload');
                }
            }
        }

        return $this->render('gallery_image/upload.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/gallery', name: 'gallery_index')]
    public function index(): Response
    {
        $cloudStorageType = $_ENV['CLOUD_STORAGE_DRIVER'] ?? 's3';
        $images = $this->em->getRepository(GalleryImage::class)->findBy(['cloudStorageType' => $cloudStorageType]);

        $files = [];
        foreach ($images as $image) {
            try {
                $posterImagePath = $this->cache->get(
                    'poster_image_' . $image->getId(),
                    function (ItemInterface $item) use ($image) {
                        $item->expiresAfter(3600);
                        $this->log('Cache miss for image ID ' . $image->getId());
                        return $this->generatePosterImage($image);
                    }
                );

                if ($posterImagePath) {
                    $this->log('Cache hit for image ID ' . $image->getId());
                }

                $image->setPosterImagePath($posterImagePath);
                $files[] = [
                    'id' => $image->getId(),
                    'name' => $image->getName(),
                    'type' => $image->getType(),
                    'cloudStorageType' => $image->getCloudStorageType(),
                    'url' => $this->generateUrl('media_play', ['id' => $image->getId()]),
                    // Let JS fetch signed video URL
                    'posterUrl' => $image->getPosterImagePath(),
                ];
            } catch (\Throwable $e) {
                $this->log('Failed to retrieve poster image for ID ' . $image->getId() . ': ' . $e->getMessage());
            }
        }

        return $this->render('gallery_image/index.html.twig', [
            'images' => $files,
        ]);
    }

    private function log(string $message, array $context = []): void
    {
        if ($this->DEBUG_UPLOAD) {
            $this->log->info('[UPLOAD DEBUG] ' . $message, $context);
        }
    }

    #[Route('/gallery/generate-poster/{id}', name: 'gallery_generate_poster')]
    public function generatePosterImage(GalleryImage $galleryImage): string
    {
        $ffmpegPath = $_ENV['FFMPEG_PATH'] ?? 'ffmpeg';
        //$posterDir = $this->getParameter('kernel.project_dir') . '/public/images/posters';
        $posterDir = $this->getParameter(
                'kernel.project_dir'
            ) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'posters';
        $posterFilename = $galleryImage->getId() . '.jpg';
        $posterFullPath = $posterDir . DIRECTORY_SEPARATOR . $posterFilename;
        $publicRelativePath = 'images/posters/' . $posterFilename;
        $this->log('Poster dir: ' . $posterDir);
        $this->log('Poster path: ' . $posterFullPath);
        $this->log('public relative path: ' . $publicRelativePath);
        if (!is_dir($posterDir)) {
            mkdir($posterDir, 0775, true);
        }

        if (file_exists($posterFullPath)) {
            return $publicRelativePath;
        }

        $localVideoPath = tempnam(sys_get_temp_dir(), 'video_');
        $posterTempPath = tempnam(sys_get_temp_dir(), 'poster_') . '.jpg';

        try {
            $stream = $this->cloudStorage->downloadStream($galleryImage->getFilePath());
            file_put_contents($localVideoPath, stream_get_contents($stream));
            fclose($stream);
            -
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($localVideoPath);

            if (str_starts_with($mimeType, 'video/')) {
                // ffmpeg poster from video
                $command = sprintf(
                    '%s -i %s -ss 00:00:01.000 -vframes 1 -vf scale=640:-1 %s',
                    escapeshellarg($ffmpegPath),
                    escapeshellarg($localVideoPath),
                    escapeshellarg($posterTempPath)
                );
                exec($command, $output, $returnVar);
            } else {
                // Resize image input with ffmpeg
                $command = sprintf(
                    '%s -i %s -vf scale=640:-1 %s',
                    escapeshellarg($ffmpegPath),
                    escapeshellarg($localVideoPath),
                    escapeshellarg($posterTempPath)
                );
                exec($command, $output, $returnVar);
            }
            if ($returnVar !== 0 || !file_exists($posterTempPath)) {
                throw new \RuntimeException("Failed to generate poster image: " . implode("\n", $output));
            }

            rename($posterTempPath, $posterFullPath);

            return $publicRelativePath;
        } finally {
            if (file_exists($localVideoPath)) {
                unlink($localVideoPath);
            }
            if (file_exists($posterTempPath)) {
                unlink($posterTempPath);
            }
        }
    }
}
