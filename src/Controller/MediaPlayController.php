<?php

namespace App\Controller;

use App\Entity\GalleryImage;
use App\Service\CloudStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class MediaPlayController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private CloudStorageInterface $cloudStorage
    ) {}

    #[Route('/media/play/{id}', name: 'media_play', methods: ['GET'])]
    public function play(int $id): JsonResponse
    {
        $image = $this->em->getRepository(GalleryImage::class)->find($id);

        if (!$image) {
            return $this->json(['error' => 'Not found'], 404);
        }

        if($_ENV['CDN'] === 'true') {
            $url = $_ENV['CDN_DOMAIN']  . '/' . $_ENV['CLOUD_FOLDER'] . '/' . $image->getFilePath();
        } else {
            $url = $this->cloudStorage->getSignedUrl($_ENV['CLOUD_FOLDER'] . '/' . $image->getFilePath(), '+1 hour');
        }

        return $this->json(['url' => $url]);
    }
}
