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

class GalleryImageController extends AbstractController
{
    private bool $DEBUG_UPLOAD = true;

    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $log,
        private CloudStorageInterface $cloudStorage
    ) {}

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
                    if ($this->DEBUG_UPLOAD) $this->log('Calling cloudStorage->upload()');

                    $this->cloudStorage->upload($cloudPath, $file->getPathname());

                    $galleryImage->setFilePath($cloudPath);
                    $galleryImage->setName($form->get('name')->getData());

                    $mime = $file->getMimeType();
                    if (str_starts_with($mime, 'image/')) {
                        $galleryImage->setType('image');
                    } elseif (str_starts_with($mime, 'video/')) {
                        $galleryImage->setType('video');
                    }

                    $this->em->persist($galleryImage);
                    $this->em->flush();

                    if ($this->DEBUG_UPLOAD) $this->log('Saved to DB with ID: ' . $galleryImage->getId());

                    return $this->redirectToRoute('gallery_index');
                } catch (\Throwable $e) {
                    $this->addFlash('error', 'Upload failed: ' . $e->getMessage());
                    if ($this->DEBUG_UPLOAD) $this->log('Exception: ' . $e->getMessage());
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
        $images = $this->em->getRepository(GalleryImage::class)->findAll();

        $files = [];
        foreach ($images as $image) {
            $files[] = [
                'id' => $image->getId(),
                'name' => $image->getName(),
                'type' => $image->getType(),
                'url' => $this->generateUrl('media_image', ['id' => $image->getId()]),
            ];
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

    #[Route('/test-list', name: 'test_dropbox_list')]
    public function testList(FilesystemOperator $dropbox): Response
    {
        try {
            $dropbox->write('moo-' . uniqid() . '.txt', 'Testing file visibility.');
            $listing = $dropbox->listContents('', false);
            $output = "<h1>Dropbox File List</h1>";

            foreach ($listing as $item) {
                $output .= $item->path() . '<br>';
            }

            return new Response($output);
        } catch (\Throwable $e) {
            return new Response('❌ Error listing Dropbox files: ' . $e->getMessage());
        }
    }

    #[Route('/test-upload', name: 'test_upload')]
    public function test(FilesystemOperator $dropbox): Response
    {
        $dropbox->write('test-file.txt', 'This is a Dropbox test upload!');
        return new Response('✅ Uploaded to Dropbox!');
    }

    #[Route('/test-dropbox-auth', name: 'test_dropbox_auth')]
    public function testDropboxAuth(HttpClientInterface $http): Response
    {
        $clientId = $_ENV['DROPBOX_CLIENT_ID'] ?? 'MISSING';
        $clientSecret = $_ENV['DROPBOX_CLIENT_SECRET'] ?? 'MISSING';
        $refreshToken = $_ENV['DROPBOX_REFRESH_TOKEN'] ?? 'MISSING';

        ob_start(); // capture all output

        echo "Testing Dropbox Auth...\n";
        echo "Client ID: " . $clientId . "\n";
        echo "Client Secret: " . substr($clientSecret, 0, 5) . "...\n";
        echo "Refresh Token: " . substr($refreshToken, 0, 5) . "...\n\n";

        try {
            $response = $http->request('POST', 'https://api.dropboxapi.com/oauth2/token', [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ],
            ]);

            $data = $response->toArray();

            echo "SUCCESS:\n";
            print_r($data);

        } catch (\Exception $e) {
            echo "ERROR:\n";
            echo $e->getMessage();
        }

        return new Response('<pre>' . ob_get_clean() . '</pre>');
    }
}
