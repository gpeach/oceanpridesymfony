<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DiveBoatController extends AbstractController
{
    #[Route('/dive-boats', name: 'app_diveboat_index')]
    public function index(): Response
    {
        return $this->render('business-pages/dive-boats/index.html.twig', [
            'controller_name' => 'DiveBoatController',
        ]);
    }
}
