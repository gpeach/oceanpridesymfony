<?php

namespace App\Controller;

use App\Entity\GalleryImage;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Service\DropboxClientFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GalleryImageController extends AbstractController
{
    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[Route('/gallery/upload', name: 'gallery_upload')]
    public function upload(Request $request, DropboxClientFactory $dropboxFactory): Response
    {
        $galleryImage = new GalleryImage();
        $dropbox = $dropboxFactory->create();

        $form = $this->createFormBuilder($galleryImage)
            ->add('name', TextType::class)
            ->add('file', FileType::class, [
                'mapped' => false,
                'required' => true,
            ])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $file = $form->get('file')->getData();

            if ($file) {
                $filename = uniqid() . '.' . $file->guessExtension();
                $dropboxPath = '/client_photos/' . $filename;

                try {
                    $this->uploadChunked($dropbox, $dropboxPath, $file->getPathname());

                    $galleryImage->setFilePath($dropboxPath);

                    $mime = $file->getMimeType();
                    if (str_starts_with($mime, 'image/')) {
                        $galleryImage->setType('image');
                    } elseif (str_starts_with($mime, 'video/')) {
                        $galleryImage->setType('video');
                    }

                    $this->em->persist($galleryImage);
                    $this->em->flush();

                    return $this->redirectToRoute('gallery_index');
                } catch (\Throwable $e) {
                    $this->addFlash('error', 'Upload failed: ' . $e->getMessage());
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

    private function uploadChunked(\Spatie\Dropbox\Client $dropbox, string $dropboxPath, string $localPath, int $chunkSize = 8 * 1024 * 1024): void
    {
        if (!file_exists($localPath)) {
            throw new \RuntimeException("File not found: $localPath");
        }

        if (!is_readable($localPath)) {
            throw new \RuntimeException("File is not readable: $localPath");
        }

        $fileSize = filesize($localPath);
        $stream = fopen($localPath, 'rb');

        if ($fileSize <= 150 * 1024 * 1024) {
            $dropbox->upload($dropboxPath, $stream, 'add');
            fclose($stream);
            return;
        }

        $offset = 0;
        $uploadSessionId = null;

        while (!feof($stream)) {
            $chunk = fread($stream, $chunkSize);
            $length = strlen($chunk);

            if ($offset === 0) {
                $response = $dropbox->uploadSessionStart($chunk, false);
                $uploadSessionId = $response['session_id'];
            } else {
                $cursor = new \Spatie\Dropbox\UploadSessionCursor($uploadSessionId, $offset);
                $dropbox->uploadSessionAppendV2($cursor, $chunk);
            }

            $offset += $length;
        }

        $cursor = new \Spatie\Dropbox\UploadSessionCursor($uploadSessionId, $offset);
        $commit = [
            'path' => $dropboxPath,
            'mode' => 'add',
            'autorename' => true,
            'mute' => false,
        ];

        $dropbox->uploadSessionFinish($cursor, $commit, '');
        fclose($stream);
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

