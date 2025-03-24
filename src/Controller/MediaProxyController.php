<?php

// src/Controller/MediaProxyController.php
namespace App\Controller;

use App\Entity\GalleryImage;
use App\Service\DropboxClientFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class MediaProxyController extends AbstractController
{
    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    #[Route('/media/image/{id}', name: 'media_image')]
    public function image(int $id, DropboxClientFactory $dropboxFactory): Response
    {
        $image = $this->em->getRepository(GalleryImage::class)->find($id);

        if (!$image) {
            throw $this->createNotFoundException('Image not found.');
        }

        $dropbox = $dropboxFactory->create();

        try {
            $stream = $dropbox->download($image->getFilePath()); // returns a resource
            $contents = stream_get_contents($stream);            // get the content
            fclose($stream);                                     // close the stream

            return new Response($contents, 200, [
                'Content-Type' => $this->guessMimeType($image->getFilePath()),
                'Content-Disposition' => 'inline; filename="' . basename($image->getFilePath()) . '"',
            ]);
        } catch (\Exception $e) {
            return new Response('Error loading media: ' . $e->getMessage(), 404);
        }
    }

    private function guessMimeType(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'mp4' => 'video/mp4',
            default => 'application/octet-stream',
        };
    }
}

