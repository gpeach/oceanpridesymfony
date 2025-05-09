<?php

namespace App\Command;

use App\Controller\GalleryImageController;
use App\Entity\GalleryImage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Cache\CacheInterface;

class CachePostersCommand extends Command
{
    protected static $defaultName = 'app:cache-posters';

    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $log,
        private CacheInterface $cache,
        private GalleryImageController $galleryImageController
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::$defaultName)
            ->setDescription('Batch cache poster images for existing uploads')
            ->addOption(
                'all',         // long name: --all
                'a',           // shortcut: -a
                InputOption::VALUE_NONE, // it’s a boolean flag
                'Run the command on all items'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runAll = $input->getOption('all');
        $io = new SymfonyStyle($input, $output);
        $cloudStorageType = $_ENV['CLOUD_STORAGE_DRIVER'] ?? 's3';
        $images = $this->em->getRepository(GalleryImage::class)->findBy(['cloudStorageType' => $cloudStorageType]);
        $cachedCount = 0;

        foreach ($images as $image) {
            try {
                if(!$image->getPosterImagePath() || $runAll) {
                    $posterImagePath = $this->galleryImageController->generatePosterImage($image);
                    $image->setPosterImagePath($posterImagePath);
                    $this->em->persist($image);
                    $cachedCount++;
                }
            } catch (\Throwable $e) {
                $this->log->error('Failed to cache poster image for ID ' . $image->getId() . ': ' . $e->getMessage());
            }
        }
        $this->em->flush();

        $io->success('Cached poster images for ' . $cachedCount . ' uploads.');

        return Command::SUCCESS;
    }

//    private function generatePosterImage(GalleryImage $galleryImage): string
//    {
//        // Implement the logic to generate the poster image
//        // This is a placeholder implementation
//        return '/path/to/generated/poster.jpg';
//    }
}
