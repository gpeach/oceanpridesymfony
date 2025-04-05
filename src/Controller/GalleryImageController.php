<?php

namespace App\Controller;

ini_set('memory_limit', '512M');

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
use Symfony\Component\Filesystem\Path;

class GalleryImageController extends AbstractController
{
    private bool $DEBUG_UPLOAD = true;

    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $log,
        private CloudStorageInterface $cloudStorage,
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

            if ($request->isXmlHttpRequest() && !$form->isValid()) {
                $errors = [];
                foreach ($form->getErrors(true) as $error) {
                    $errors[] = $error->getMessage();
                }

                return $this->json([
                    'status' => 'error',
                    'message' => 'Validation failed.',
                    'errors' => $errors,
                ], 400);
            }
        }



        if ($form->isSubmitted() && $form->isValid()) {
            $file = $form->get('file')->getData();

            if ($file) {
                $filename = uniqid() . '.' . $file->guessExtension();
                $cloudPath = $_ENV['CLOUD_FOLDER'] .'/'. $filename;

                try {
                    if ($this->DEBUG_UPLOAD) {
                        $this->log('Calling cloudStorage->upload()');
                    }

                    $this->cloudStorage->upload($cloudPath, $file->getPathname());

                    $galleryImage->setFilePath($filename);
                    $galleryImage->setName($form->get('name')->getData());

                    $mime = $file->getMimeType();
                    if (str_starts_with($mime, 'image/')) {
                        $galleryImage->setType('image');
                    } elseif (str_starts_with($mime, 'video/')) {
                        $galleryImage->setType('video');
                    }

                    $cloudStorageType = $_ENV['CLOUD_STORAGE_DRIVER'] ?? 's3';
                    $galleryImage->setCloudStorageType($cloudStorageType);
                    $this->em->persist($galleryImage);
                    $this->em->flush();

                    $posterPath = Path::canonicalize($this->getParameter('kernel.project_dir') . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR .$galleryImage->getPosterImagePath());
                    if(!$galleryImage->getPosterImagePath()){
                        $posterImagePath = $this->generatePosterImage($galleryImage);
                        $galleryImage->setPosterImagePath($posterImagePath);
                        $this->em->persist($galleryImage);
                        $this->em->flush();
                    }

                    if ($this->DEBUG_UPLOAD) {
                        $this->log('Saved to DB with ID: ' . $galleryImage->getId());
                    }

                    return $this->redirectToRoute('gallery_index');
                } catch (\Throwable $e) {
                    if ($request->isXmlHttpRequest()) {
                        return $this->json([
                            'status' => 'error',
                            'message' => 'Upload failed: ' . $e->getMessage(),
                        ], 500);
                    }
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
                if(!$image->getPosterImagePath()){
                    $posterImagePath = $this->generatePosterImage($image);
                    $image->setPosterImagePath($posterImagePath);
                    $this->em->persist($image);
                    $this->em->flush();
                }

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
        if ($this->DEBUG_UPLOAD) {
            $this->log('hit generatePosterImage');
        }
        $ffmpegPath = $_ENV['FFMPEG_PATH'] ?? 'ffmpeg';
        $posterDir = Path::canonicalize($this->getParameter('kernel.project_dir') . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'posters');
        $tmpDir = Path::canonicalize($this->getParameter('kernel.project_dir') . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'tmp');


        if (!is_dir($posterDir)) {
            mkdir($posterDir, 0775, true);
        }

        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }

        $localVideoPath = Path::canonicalize($this->getParameter('kernel.project_dir') . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . $galleryImage->getFilePath());

        $cloudDownloadPath = $_ENV['CLOUD_FOLDER'] . '/' . $galleryImage->getFilePath();

        try {
            $stream = $this->cloudStorage->downloadStream($cloudDownloadPath);
            $fp = fopen($localVideoPath, 'w');
            while (!feof($stream)) {
                fwrite($fp, fread($stream, 8192));
            }
            fclose($fp);
            $posterFileExtension = 'jpg';
            $mimeType = mime_content_type($localVideoPath);

            $posterFilename = $galleryImage->getId() . '.' . $posterFileExtension;

            $posterTempPath = Path::canonicalize($this->getParameter('kernel.project_dir') . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . $posterFilename);
            $posterFullPath = $posterDir . DIRECTORY_SEPARATOR . $posterFilename;
            $publicRelativePath = 'images/posters/' . $posterFilename;

            if ($this->DEBUG_UPLOAD) {
                $this->log->info('[UPLOAD DEBUG] $posterFilename: ' . $posterFilename);
                $this->log->info('[UPLOAD DEBUG] $posterTempPath: ' . $posterTempPath);
                $this->log->info('[UPLOAD DEBUG] $posterFullPath: ' . $posterFullPath);
                $this->log->info('[UPLOAD DEBUG] $publicRelativePath: ' . $publicRelativePath);
            }


            if (str_starts_with($mimeType, 'video/') && !file_exists($posterFullPath)) {
                // ffmpeg poster from video
                $command = sprintf(
                    '%s -i %s -ss 00:00:01.000 -vframes 1 -vf scale=640:-1 %s &',
                    escapeshellarg($ffmpegPath),
                    escapeshellarg($localVideoPath),
                    escapeshellarg($posterTempPath)
                );
                exec($command, $output, $returnVar);
            } else {
                if (!file_exists($posterFullPath)) {
                    // Resize image input with ffmpeg
                    $command = sprintf(
                        '%s -i %s -vf scale=640:-1 %s &',
                        escapeshellarg($ffmpegPath),
                        escapeshellarg($localVideoPath),
                        escapeshellarg($posterTempPath)
                    );
                    exec($command, $output, $returnVar);
                }
            }
            if ($returnVar !== 0 || !file_exists($posterTempPath)) {
                throw new \RuntimeException("Failed to generate poster image: " . implode("\n", $output));
            }

            try {
                rename($posterTempPath, $posterFullPath);
            } catch (\Throwable $e) {
                $this->log('Failed to rename poster image: ' . $e->getMessage());
                throw new \RuntimeException("Failed to rename poster image: " . $e->getMessage());
            }

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
