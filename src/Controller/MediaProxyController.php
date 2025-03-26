<?php

namespace App\Controller;

use App\Entity\GalleryImage;
use App\Service\CloudStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\Routing\Annotation\Route;

class MediaProxyController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private CloudStorageInterface $cloudStorage
    ) {}

    #[Route('/media/image/{id}', name: 'media_image', methods: ['GET'])]
    public function image(int $id): Response
    {
        $image = $this->em->getRepository(GalleryImage::class)->find($id);

        if (!$image) {
            throw $this->createNotFoundException('Image not found.');
        }

        $path = $image->getFilePath();
        $filename = basename($path);
        $mimeType = $this->guessMimeType($filename);

        try {
            if ($mimeType === 'video/mp4') {
                $stream = $this->cloudStorage->downloadStream($path);

                return new StreamedResponse(function () use ($stream) {
                    fpassthru($stream);
                    fclose($stream);
                }, 200, [
                    'Content-Type' => 'video/mp4',
                    'Content-Disposition' => 'inline; filename="' . $filename . '"',
                    'Cache-Control' => 'public, max-age=31536000',
                ]);
            }

            $fileData = $this->cloudStorage->download($path);

            $disposition = HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_INLINE,
                $filename
            );

            $response = new Response($fileData);
            $response->headers->set('Content-Disposition', $disposition);
            $response->headers->set('Content-Type', $mimeType);
            $response->headers->set('Cache-Control', 'public, max-age=31536000');

            return $response;
        } catch (\Throwable $e) {
            return new Response("File not found or inaccessible: $path", 404);
        }
    }

    private function guessMimeType(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'mp4' => 'video/mp4',
            'mp3' => 'audio/mpeg',
            default => 'application/octet-stream',
        };
    }
}
