<?php

namespace App\Service;

use Spatie\Dropbox\Client;
use Spatie\Dropbox\UploadSessionCursor;

class DropboxStorageService implements CloudStorageInterface
{
    public function __construct(private Client $client)
    {
    }

    public function upload(string $cloudPath, string $localPath): void
    {
        $fileSize = filesize($localPath);

        if (!file_exists($localPath) || !is_readable($localPath)) {
            throw new \RuntimeException("Invalid or unreadable file: $localPath");
        }

        if ($fileSize <= 150 * 1024 * 1024) {
            $stream = fopen($localPath, 'rb');
            $this->client->upload($cloudPath, $stream, 'add');
            fclose($stream);
            return;
        }

        $this->uploadChunked($cloudPath, $localPath);
    }

    private function uploadChunked(string $dropboxPath, string $localPath, int $chunkSize = 8 * 1024 * 1024): void
    {
        $stream = fopen($localPath, 'rb');
        $fileSize = filesize($localPath);
        $offset = 0;
        $uploadSessionId = null;
        $lastChunk = null;

        while (!feof($stream)) {
            $chunk = fread($stream, $chunkSize);
            $length = strlen($chunk);
            $lastChunk = $chunk;

            if ($offset === 0) {
                $result = $this->client->uploadSessionStart($chunk, false);
                $uploadSessionId = $result->session_id;
            } else {
                $cursor = new UploadSessionCursor($uploadSessionId, $offset);
                $this->client->uploadSessionAppend($chunk, $cursor);
            }

            $offset += $length;
        }

        $cursor = new UploadSessionCursor($uploadSessionId, $offset);

        $this->client->uploadSessionFinish(
            $lastChunk,
            $cursor,
            $dropboxPath,
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
            throw new \RuntimeException("Expected stream from Dropbox, got " . gettype($stream));
        }

        $contents = stream_get_contents($stream);
        fclose($stream);

        if ($contents === false) {
            throw new \RuntimeException("Failed to read stream from Dropbox.");
        }

        return $contents;
    }

    public function downloadStream(string $cloudPath): mixed
    {
        $stream = $this->client->download($cloudPath);

        if (!is_resource($stream)) {
            throw new \RuntimeException("Expected stream from Dropbox, got " . gettype($stream));
        }

        return $stream;
    }

    public function list(string $directory): array
    {
        $response = $this->client->listFolder($directory);

        return array_map(fn($entry) => $entry['path_display'], $response['entries'] ?? []);
    }

    public function delete(string $path): void
    {
        $this->client->delete($path);
    }
}
