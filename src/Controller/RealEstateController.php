<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RealEstateController extends AbstractController
{
    #[Route('/real-estate', name: 'app_real_estate_index')]
    public function index(): Response
    {
        return $this->render('business-pages/real-estate/index.html.twig', [
            'controller_name' => 'RealEstateController',
        ]);
    }
}
