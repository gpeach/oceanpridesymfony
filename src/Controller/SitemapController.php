<?php

namespace App\Controller;

use App\Entity\GalleryImage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SitemapController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RouterInterface $router
    ) {
    }

    #[Route('/sitemap.xml', name: 'sitemap', methods: ['GET'])]
    public function index(): Response
    {
        $urls = [];

        // Add static routes
        $staticRoutes = [
            'app_index',
            'app_contact',
            'gallery_index',
            'gallery_photos',
            'gallery_upload',
            'app_login',
            'app_logout',
            'sample_reel',
            'hype_reel',
            'property_reel',
            'lifestyle_reel',
            'day_to_night_reel'
        ];
        foreach ($staticRoutes as $routeName) {
            $urls[] = [
                'loc' => $this->router->generate($routeName, [], RouterInterface::ABSOLUTE_URL),
                'lastmod' => (new \DateTime())->format('Y-m-d'),
            ];
        }

        // Add dynamic routes for gallery items
        $galleryItems = $this->em->getRepository(GalleryImage::class)->findAll();
        foreach ($galleryItems as $item) {
            $urls[] = [
                'loc' => $this->router->generate(
                    'gallery_item',
                    ['id' => $item->getId()],
                    RouterInterface::ABSOLUTE_URL
                ),
                'lastmod' => $item->getUpdatedAt() ? $item->getUpdatedAt()->format('Y-m-d') : (new \DateTime())->format('Y-m-d'),
            ];
        }

        // Generate XML
        $xml = new \SimpleXMLElement('<urlset/>');
        $xml->addAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        foreach ($urls as $url) {
            $urlElement = $xml->addChild('url');
            $urlElement->addChild('loc', $url['loc']);
            $urlElement->addChild('lastmod', $url['lastmod']);
        }

        return new Response($xml->asXML(), 200, ['Content-Type' => 'application/xml']);
    }
}
