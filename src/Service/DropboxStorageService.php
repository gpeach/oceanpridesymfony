<?php

namespace App\Service;

use App\Service\CloudStorageInterface;
use Spatie\Dropbox\Client;
use Spatie\Dropbox\UploadSessionCursor;
use RuntimeException;

class DropboxStorageService implements CloudStorageInterface
{
    public function __construct(private Client $client) {}

    public function upload(string $cloudPath, string $localPath): void
    {
        if (!file_exists($localPath) || !is_readable($localPath)) {
            throw new RuntimeException("Invalid or unreadable file: $localPath");
        }

        $fileSize = filesize($localPath);

        if ($fileSize <= 150 * 1024 * 1024) {
            $stream = fopen($localPath, 'rb');
            $this->client->upload($cloudPath, $stream, 'add');
            fclose($stream);
        } else {
            $this->uploadChunked($cloudPath, $localPath);
        }

        // Generate and cache the poster image
        //$posterImagePath = $this->generateAndCachePosterImage($cloudPath);
    }

    private function uploadChunked(string $cloudPath, string $localPath, int $chunkSize = 8 * 1024 * 1024): void
    {
        $stream = fopen($localPath, 'rb');
        $offset = 0;
        $uploadSessionId = null;

        while (!feof($stream)) {
            $chunk = fread($stream, $chunkSize);
            $length = strlen($chunk);

            if ($offset === 0) {
                $result = $this->client->uploadSessionStart($chunk, false);
                $uploadSessionId = $result['session_id'];
            } else {
                $cursor = new UploadSessionCursor($uploadSessionId, $offset);
                $this->client->uploadSessionAppend($chunk, $cursor);
            }

            $offset += $length;
        }

        $cursor = new UploadSessionCursor($uploadSessionId, $offset);
        $this->client->uploadSessionFinish(
            $chunk, // last chunk
            $cursor,
            $cloudPath,
            'add',
            true,
            false
        );

        fclose($stream);
    }

    public function download(string $cloudPath): string
    {
        $stream = $this->client->download($cloudPath);
        if (!is_resource($stream)) {
            throw new RuntimeException("Expected resource from Dropbox, got " . gettype($stream));
        }

        $contents = stream_get_contents($stream);
        fclose($stream);

        if ($contents === false) {
            throw new RuntimeException("Failed to read Dropbox stream.");
        }

        return $contents;
    }

    public function downloadStream(string $cloudPath): mixed
    {
        $stream = $this->client->download($cloudPath);
        if (!is_resource($stream)) {
            throw new RuntimeException("Expected resource from Dropbox, got " . gettype($stream));
        }

        $tmpFile = tmpfile();
        if (!is_resource($tmpFile)) {
            throw new RuntimeException("Failed to create temporary file.");
        }

        stream_copy_to_stream($stream, $tmpFile);
        rewind($tmpFile);

        return $tmpFile;
    }

    public function list(string $directory): array
    {
        $response = $this->client->listFolder($directory);
        return array_map(
            fn(array $entry) => $entry['path_display'],
            $response['entries'] ?? []
        );
    }

    public function delete(string $path): void
    {
        $this->client->delete($path);
    }

    public function exists(string $cloudPath): bool
    {
        try {
            $this->client->getMetadata($cloudPath);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function getSignedUrl(string $cloudPath, string $expires = '+1 hour'): string
    {
        try {
            $response = $this->client->getTemporaryLink($cloudPath);
            if (!isset($response)) {
                throw new RuntimeException('Dropbox did not return a valid temporary link.');
            }
            return $response;
        } catch (\Throwable $e) {
            throw new RuntimeException('Dropbox error: ' . $e->getMessage(), 0, $e);
        }
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
            $stream = $this->client->download($cloudPath);
            if (!is_resource($stream)) {
                throw new RuntimeException("Expected resource from Dropbox, got " . gettype($stream));
            }

            file_put_contents($localVideoPath, stream_get_contents($stream));
            fclose($stream);

            // Generate the poster image using ffmpeg
            $command = sprintf(
                'ffmpeg -i %s -ss 00:00:01.000 -vframes 1 %s',
                escapeshellarg($localVideoPath),
                escapeshellarg($posterImagePath)
            );

            exec($command, $output, $returnVar);
            if ($returnVar !== 0) {
                throw new RuntimeException("Failed to generate poster image: " . implode("\n", $output));
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
