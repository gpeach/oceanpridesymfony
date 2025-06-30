<?php

namespace App\Controller;

use App\Entity\GalleryImage;
use App\Service\CloudStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\JsonResponse;

class GalleryImageController extends AbstractController
{
    private bool $DEBUG_UPLOAD = true;

    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $log,
        private CloudStorageInterface $cloudStorage
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
                'required' => false,
            ])
            ->add('videoUrl', UrlType::class, [
                'required' => false,
                'mapped' => false,
                'label' => 'YouTube or Vimeo URL'
            ])
            ->add('description', TextareaType::class, [
                'required' => false
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
                $cloudPath = $_ENV['CLOUD_FOLDER'] . '/' . $filename;

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

//                    $posterPath = Path::canonicalize(
//                        $this->getParameter(
//                            'kernel.project_dir'
//                        ) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . $galleryImage->getPosterImagePath()
//                    );
                    if (!$galleryImage->getPosterImagePath()) {
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
                    $this->addFlash('error', 'Upload failed: ' . $e->getMessage());
                    if ($this->DEBUG_UPLOAD) {
                        $this->log('Exception: ' . $e->getMessage());
                    }
                    return $this->redirectToRoute('gallery_upload');
                }
            } else {
                $url = $form->get('videoUrl')->getData();
                if (preg_match(
                    '#^(?:https?://)?(?:www\.)?(?:youtu\.be/|youtube\.com/(?:watch\?(?:.*&)?v=|embed/))([A-Za-z0-9_-]{11})#x',
                    $url,
                    $m
                )) {
                    $videoId = $m[1];
                    $galleryImage->setProvider('youtube')
                        ->setExternalId($videoId)
                        ->setExternalUrl($url)
                        ->setType('video');
                    $this->em->persist($galleryImage);
                    $this->em->flush();

                    return $this->redirectToRoute('gallery_index');
                    // poster image:
                    $galleryImage->setPosterImagePath("https://img.youtube.com/vi/{$videoId}/hqdefault.jpg");
                } elseif (preg_match('/vimeo\.com\/(\d+)/', $url, $m)) {
                    $galleryImage->setProvider('vimeo')
                        ->setExternalId($m[1])
                        ->setExternalUrl($url)
                        ->setType('video');
                    $this->em->persist($galleryImage);
                    $this->em->flush();

                    return $this->redirectToRoute('gallery_index');
                    // Optional: Vimeo oEmbed thumb
                } else {
                    $this->addFlash('danger', 'Unrecognised video URL');
                    return $this->redirectToRoute('gallery_upload');
                }
            }

            $this->em->persist($galleryImage);
            $this->em->flush();
        }

        return $this->render('gallery_image/upload.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/gallery', name: 'gallery_index')]
    public function index(): Response
    {
        $cloudStorageType = $_ENV['CLOUD_STORAGE_DRIVER'] ?? 's3';

        $qb = $this->em->getRepository(GalleryImage::class)
            ->createQueryBuilder('g');

        $orX = $qb->expr()->orX(
            $qb->expr()->andX(
                $qb->expr()->isNotNull('g.provider'),
                $qb->expr()->neq('g.provider', ':empty')
            ),
            $qb->expr()->eq('g.cloudStorageType', ':driver')
        );

        $qb->where($orX)
            ->andWhere($qb->expr()->eq('g.type', ':type'))
            ->setParameter('empty', '')
            ->setParameter('driver', $cloudStorageType)
            ->setParameter('type', 'video')
            ->orderBy('g.id', 'DESC');

//            ->where('g.provider IS NOT NULL')
//            ->orWhere('g.cloudStorageType = :type')
//            ->setParameter('type', $cloudStorageType);   // or whatever ordering you prefer

        $images = $qb->getQuery()->getResult();

        $files = [];
        foreach ($images as $image) {
            try {
                if (!$image->getPosterImagePath() && ($image->getProvider() == null || $image->getProvider() == '')) {
                    $posterImagePath = $this->generatePosterImage($image);
                    $image->setPosterImagePath($posterImagePath);
                    $this->em->persist($image);
                    $this->em->flush();
                }

                // if it’s an external video, link directly to that URL; otherwise use our proxy
                if ($image->getProvider() !== null && $image->getProvider() !== '') {
                    $mediaUrl = $image->getExternalUrl();
                } else {
                    $mediaUrl = $this->generateUrl('media_play', ['id' => $image->getId()]);
                }

                $files[] = [
                    'id' => $image->getId(),
                    'name' => $image->getName(),
                    'type' => $image->getType(),
                    'provider' => $image->getProvider(),
                    'url' => $mediaUrl,
                    'externalId' => $image->getExternalId(),
                    'posterUrl' => $image->getPosterImagePath(),
                    'description' => $image->getDescription(),
                ];
            } catch (\Throwable $e) {
                $this->log('Failed to retrieve poster image for ID ' . $image->getId() . ': ' . $e->getMessage());
            }
        }

        return $this->render('gallery_image/index.html.twig', [
            'images' => $files,
        ]);
    }


    #[Route('/gallery/photos', name: 'gallery_photos')]
    public function photos(): Response
    {
        $cloudStorageType = $_ENV['CLOUD_STORAGE_DRIVER'] ?? 's3';

        $qb = $this->em->getRepository(GalleryImage::class)
            ->createQueryBuilder('g');

        $orX = $qb->expr()->orX(
            $qb->expr()->andX(
                $qb->expr()->isNotNull('g.provider'),
                $qb->expr()->neq('g.provider', ':empty')
            ),
            $qb->expr()->eq('g.cloudStorageType', ':driver')
        );

        $qb->where($orX)
            ->andWhere($qb->expr()->eq('g.type', ':type'))
            ->setParameter('empty', '')
            ->setParameter('driver', $cloudStorageType)
            ->setParameter('type', 'image')
            ->orderBy('g.id', 'DESC');

//            ->where('g.provider IS NOT NULL')
//            ->orWhere('g.cloudStorageType = :type')
//            ->setParameter('type', $cloudStorageType);   // or whatever ordering you prefer

        $images = $qb->getQuery()->getResult();

        $files = [];
        foreach ($images as $image) {
            try {
                if (!$image->getPosterImagePath() && ($image->getProvider() == null || $image->getProvider() == '')) {
                    $posterImagePath = $this->generatePosterImage($image);
                    $image->setPosterImagePath($posterImagePath);
                    $this->em->persist($image);
                    $this->em->flush();
                }

                // if it’s an external video, link directly to that URL; otherwise use our proxy
                if ($image->getProvider() !== null && $image->getProvider() !== '') {
                    $mediaUrl = $image->getExternalUrl();
                } else {
                    $mediaUrl = $this->generateUrl('media_play', ['id' => $image->getId()]);
                }

                $files[] = [
                    'id' => $image->getId(),
                    'name' => $image->getName(),
                    'type' => $image->getType(),
                    'provider' => $image->getProvider(),
                    'url' => $mediaUrl,
                    'externalId' => $image->getExternalId(),
                    'posterUrl' => $image->getPosterImagePath(),
                    'description' => $image->getDescription(),
                ];
            } catch (\Throwable $e) {
                $this->log('Failed to retrieve poster image for ID ' . $image->getId() . ': ' . $e->getMessage());
            }
        }

        return $this->render('gallery_image/photos.html.twig', [
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
        $posterDir = Path::canonicalize(
            $this->getParameter(
                'kernel.project_dir'
            ) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'posters'
        );
        $tmpDir = Path::canonicalize(
            $this->getParameter(
                'kernel.project_dir'
            ) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'tmp'
        );


        if (!is_dir($posterDir)) {
            mkdir($posterDir, 0775, true);
        }

        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }

        $localVideoPath = Path::canonicalize(
            $this->getParameter(
                'kernel.project_dir'
            ) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . $galleryImage->getFilePath(
            )
        );

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

            $posterTempPath = Path::canonicalize(
                $this->getParameter(
                    'kernel.project_dir'
                ) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . $posterFilename
            );
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
                    '-noautorotate %s -i %s -ss 00:00:01.000 -vframes 1 -vf scale=640:-1 %s &',
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
        } catch (\Throwable $e) {
            $this->log('Error generating poster image: ' . $e->getMessage(), [
                'exception' => $e,
                'galleryImageId' => $galleryImage->getId(),
            ]);
            throw $e; // Re-throw the exception after logging
        } finally {
            if (file_exists($localVideoPath)) {
                unlink($localVideoPath);
            }
            if (file_exists($posterTempPath)) {
                unlink($posterTempPath);
            }
        }
    }

    #[Route('/gallery/s3put', name: 'gallery_s3_put_presign', methods: ['POST'])]
    public function s3PutPresign(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['name'], $data['type'])) {
            return $this->json(['error' => 'Invalid input'], 400);
        }

        $extension = pathinfo($data['name'], PATHINFO_EXTENSION);
        $filename = uniqid() . '.' . $extension;

        try {
            // ✅ This calls your new PUT presigner method
            $presignData = $this->cloudStorage->createPresignedPutUrl($filename, $data['type']);
            return $this->json($presignData);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/gallery/metadata', name: 'gallery_upload_metadata', methods: ['POST'])]
    public function uploadMetadata(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$data || !isset($data['file_path'], $data['name'], $data['type'], $data['cloud_storage_type'])) {
            return new JsonResponse(['error' => 'Invalid input'], 400);
        }

        $image = new GalleryImage();
        $image->setName($data['name']);
        $image->setFilePath($data['file_path']);
        if (str_starts_with($data['type'], 'video/')) {
            $image->setType('video');
        } elseif (str_starts_with($data['type'], 'image/')) {
            $image->setType('image');
        }
        $image->setCloudStorageType($data['cloud_storage_type']);
        $image->setUpdatedAt(new \DateTime());

        $em->persist($image);
        $em->flush();

        // Generate and save poster
        $posterPath = $this->generatePosterImage($image);
        $image->setPosterImagePath($posterPath);
        $em->flush();

        return new JsonResponse([
            'id' => $image->getId(),
            'poster_image_path' => $posterPath,
        ]);
    }

    #[Route('/gallery/delete', name: 'gallery_delete', methods: ['POST'])]
    public function deleteImage(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (!$this->isGranted('ROLE_ADMIN')) {
                return $this->json(['error' => 'Access denied'], 403);
            }

            $image = $this->em->getRepository(GalleryImage::class)->findOneBy([
                'id' => $data['imageId']
            ]);

            if (!isset($data['name'])) {
                return $this->json(['error' => 'Invalid input'], 400);
            }

            $path = $_ENV['CLOUD_FOLDER'] . '/' . $image->getFilePath();

            $this->cloudStorage->delete($path);

            if ($image) {
                $this->em->remove($image);
                $this->em->flush();
            }

            return $this->json(['success' => true]);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/gallery/item/{id}', name: 'gallery_item', methods: ['GET'])]
    public function galleryItem(int $id): Response
    {
        $media = $this->em->getRepository(GalleryImage::class)->find($id);

        if (!$media) {
            throw $this->createNotFoundException('Media not found');
        }

        return $this->render('gallery_image/item.html.twig', [
            'media' => $media,
        ]);
    }
    #[Route('/gallery/sample-reel', name: 'sample_reel')]
    public function sampleReel(Request $request): Response
    {
        $itemId = 68; // use db ID and send them to that detail page
        return $this->forward('App\\Controller\\GalleryImageController::galleryItem', [
            'id' => $itemId,
            'request' => $request,
        ]);
    }

    #[Route('/gallery/hype-reel', name: 'hype_reel')]
    public function hypeReel(Request $request): Response
    {
        $itemId = 68; // use db ID and send them to that detail page
        return $this->forward('App\\Controller\\GalleryImageController::galleryItem', [
            'id' => $itemId,
            'request' => $request,
        ]);
    }

    #[Route('/gallery/property-reel', name: 'property_reel')]
    public function propertyReel(Request $request): Response
    {
        $itemId = 48; // use db ID and send them to that detail page
        return $this->forward('App\\Controller\\GalleryImageController::galleryItem', [
            'id' => $itemId,
            'request' => $request,
        ]);
    }

    #[Route('/gallery/lifestyle-reel', name: 'lifestyle_reel')]
    public function lifestyleReel(Request $request): Response
    {
        $itemId = 48; // use db ID and send them to that detail page
        return $this->forward('App\\Controller\\GalleryImageController::galleryItem', [
            'id' => $itemId,
            'request' => $request,
        ]);
    }

    #[Route('/gallery/day-to-night-reel', name: 'day_to_night_reel')]
    public function dayToNightReel(Request $request): Response
    {
        $itemId = 48; // use db ID and send them to that detail page
        return $this->forward('App\\Controller\\GalleryImageController::galleryItem', [
            'id' => $itemId,
            'request' => $request,
        ]);
    }
}
