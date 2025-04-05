<?php

namespace App\Controller;

use App\Entity\GalleryImage;
use App\Service\CloudStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\Routing\Annotation\Route;

//use tags like this to trigger MediaProxyController
//<img src="/media/image/60" alt="Main Photo" class="img-fluid rounded shadow-sm mb-3" />
//<video controls class="w-100 h-100 rounded" style="object-fit: cover;"><source src="/media/image/59" type="video/mp4">Your browser does not support the video tag.</video>

class MediaProxyController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private CloudStorageInterface $cloudStorage
    ) {}

    #[Route('/media/image/{id}', name: 'media_image', methods: ['GET'])]
    public function image(Request $request, int $id): Response
    {
        $image = $this->em->getRepository(GalleryImage::class)->find($id);

        if (!$image) {
            throw $this->createNotFoundException('Image not found.');
        }

        $filename= $image->getFilePath();
        $cloudPath = $_ENV['CLOUD_FOLDER'] .'/'. $filename;
        $mimeType = $this->guessMimeType($filename);

        try {
            if ($mimeType === 'video/mp4') {
                $stream = $this->cloudStorage->downloadStream($cloudPath);
                rewind($stream);
                $stats = fstat($stream);
                $fileSize = $stats['size'] ?? null;

                if ($fileSize === null) {
//                    fclose($stream);
                    throw new \RuntimeException("Could not determine file size.");
                }

                $range = $request->headers->get('Range');
                $start = 0;
                $end = $fileSize - 1;

                if ($range && preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
                    $start = (int) $matches[1];
                    if ($matches[2] !== '') {
                        $end = (int) $matches[2];
                    }
                }

                $length = $end - $start + 1;
                fseek($stream, $start);

                $response = new StreamedResponse(function () use ($stream, $length) {
                    $bufferSize = 8192;
                    $bytesSent = 0;

                    while (!feof($stream) && $bytesSent < $length) {
                        $readLength = min($bufferSize, $length - $bytesSent);
                        echo fread($stream, $readLength);
                        $bytesSent += $readLength;
                        flush();
                    }

//                    fclose($stream);
                });

                $response->setStatusCode($range ? 206 : 200);
                $response->headers->set('Content-Type', 'video/mp4');
                $response->headers->set('Content-Length', $length);
                $response->headers->set('Accept-Ranges', 'bytes');
                if ($range) {
                    $response->headers->set('Content-Range', "bytes $start-$end/$fileSize");
                }
                $response->headers->set('Cache-Control', 'public, max-age=31536000');
                $response->headers->set('Content-Disposition', 'inline; filename="' . $filename . '"');

                return $response;
            }

            // fallback for images and non-video files
            $fileData = $this->cloudStorage->download($cloudPath);

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
