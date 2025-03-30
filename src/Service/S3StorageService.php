<?php

namespace App\Service;

use Aws\S3\S3Client;

class S3StorageService implements CloudStorageInterface
{
    public function __construct(private S3Client $s3, private string $bucket)
    {
    }

    public function upload(string $cloudPath, string $localPath): void
    {
        if (!file_exists($localPath) || !is_readable($localPath)) {
            throw new \RuntimeException("Invalid or unreadable file: $localPath");
        }

        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => $cloudPath,
            'SourceFile' => $localPath,
        ]);

        // Generate and cache the poster image
        //$posterImagePath = $this->generateAndCachePosterImage($cloudPath);
    }

    public function download(string $cloudPath): string
    {
        $result = $this->s3->getObject([
            'Bucket' => $this->bucket,
            'Key' => $cloudPath,
        ]);

        return (string) $result['Body'];
    }

    public function downloadStream(string $cloudPath): mixed
    {
        $result = $this->s3->getObject([
            'Bucket' => $this->bucket,
            'Key' => $cloudPath,
        ]);

        $body = $result['Body']; // This is a Guzzle StreamInterface

        if (method_exists($body, 'detach')) {
            $body = $body->detach(); // ⬅️ Detach Guzzle stream to get native PHP resource
        }

        if (!is_resource($body)) {
            throw new \RuntimeException("Expected resource stream from S3, got " . gettype($body));
        }

        // Convert to native PHP temp stream
        $tmpFile = tmpfile();

        if (!is_resource($tmpFile)) {
            throw new \RuntimeException('Unable to create temporary stream.');
        }

        stream_copy_to_stream($body, $tmpFile); // Copies content from Guzzle stream
        rewind($tmpFile);

        return $tmpFile;
    }

    public function list(string $directory): array
    {
        $result = $this->s3->listObjectsV2([
            'Bucket' => $this->bucket,
            'Prefix' => rtrim($directory, '/') . '/',
        ]);

        return array_map(fn($item) => $item['Key'], $result['Contents'] ?? []);
    }

    public function delete(string $path): void
    {
        $this->s3->deleteObject([
            'Bucket' => $this->bucket,
            'Key' => $path,
        ]);
    }

    public function getSignedUrl(string $cloudPath, string $expires = '+1 hour'): string
    {
        $cmd = $this->s3->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key'    => $cloudPath,
            'ResponseContentDisposition' => 'inline',
            'response-content-type' => 'video/mp4',
        ]);

        $request = $this->s3->createPresignedRequest($cmd, $expires);
        return (string) $request->getUri();
    }

    public function cachePosterImage(string $cloudPath, string $posterImagePath): void
    {
        $cacheDir = '/path/to/cache/directory';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }

        $cacheFilePath = $cacheDir . '/' . md5($cloudPath) . '.jpg';
        copy($posterImagePath, $cacheFilePath);
    }

    public function getCachedPosterImage(string $cloudPath): ?string
    {
        $cacheDir = '/path/to/cache/directory';
        $cacheFilePath = $cacheDir . '/' . md5($cloudPath) . '.jpg';

        return file_exists($cacheFilePath) ? $cacheFilePath : null;
    }

    private function generateAndCachePosterImage(string $cloudPath): string
    {
        $localVideoPath = tempnam(sys_get_temp_dir(), 'video_');
        $posterImagePath = tempnam(sys_get_temp_dir(), 'poster_') . '.jpg';

        try {
            // Download the video file locally
            $result = $this->s3->getObject([
                'Bucket' => $this->bucket,
                'Key' => $cloudPath,
            ]);

            file_put_contents($localVideoPath, $result['Body']);

            // Generate the poster image using ffmpeg
            $command = sprintf(
                'ffmpeg -i %s -ss 00:00:01.000 -vframes 1 %s',
                escapeshellarg($localVideoPath),
                escapeshellarg($posterImagePath)
            );

            exec($command, $output, $returnVar);
            if ($returnVar !== 0) {
                throw new \RuntimeException("Failed to generate poster image: " . implode("\n", $output));
            }

            // Cache the poster image
            $this->cachePosterImage($cloudPath, $posterImagePath);

            return $posterImagePath;
        } finally {
            // Clean up temporary files
            if (file_exists($localVideoPath)) {
                unlink($localVideoPath);
            }
        }
    }
}
