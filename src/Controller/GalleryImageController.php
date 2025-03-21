<?php

namespace App\Controller;

use App\Entity\GalleryImage;
use App\Service\DropboxLinkHelper;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class GalleryImageController extends AbstractController
{
    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    #[Route('/gallery/upload', name: 'gallery_upload')]
    public function upload(Request $request, FilesystemOperator $dropbox, DropboxLinkHelper $dropboxLinks): Response
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

        if ($form->isSubmitted() && $form->isValid()) {
            $file = $form->get('file')->getData();

            if ($file) {
                $filename = uniqid() . '.' . $file->guessExtension();
                $stream = @fopen($file->getPathName(), 'rb');

                if ($stream !== false) {
                    $dropbox->writeStream($filename, $stream);
                } else {
                    $this->addFlash('error', 'Could not open file stream for upload.');
                }

                $galleryImage->setFilePath($filename);

                // Auto-detect file type
                $mime = $file->getMimeType();
                if (str_starts_with($mime, 'image/')) {
                    $galleryImage->setType('image');
                } elseif (str_starts_with($mime, 'video/')) {
                    $galleryImage->setType('video');
                }

                // Generate and store Dropbox share link
                $shareLink = $dropboxLinks->getPreviewLink($filename);
                $galleryImage->setShareLink($shareLink);

                $this->em->persist($galleryImage);
                $this->em->flush();

                return $this->redirectToRoute('gallery_index');
            }
        }

        return $this->render('gallery_image/upload.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/gallery', name: 'gallery_index')]
    public function index(DropboxLinkHelper $dropboxLinks): Response
    {
        $images = $this->em->getRepository(GalleryImage::class)->findAll();

        $files = [];
        foreach ($images as $image) {
            $url = $image->getShareLink();

            if (!$url) {
                $url = $dropboxLinks->getPreviewLink($image->getFilePath());
                if ($url) {
                    $image->setShareLink($url);
                    $this->em->persist($image);
                }
            }

            $files[] = [
                'name' => $image->getName(),
                'url' => $url,
                'type' => $image->getType(),
            ];
        }

        $this->em->flush();

        return $this->render('gallery_image/index.html.twig', [
            'images' => $files,
        ]);
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
}

