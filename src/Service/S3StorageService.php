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

        $body = $result['Body'];

        // If it's already a stream resource (depends on S3 SDK internals)
        if (is_resource($body)) {
            return $body;
        }

        // If it's a Guzzle stream interface
        if (is_object($body) && method_exists($body, 'detach')) {
            return $body->detach(); // returns raw stream
        }

        // Fallback: create stream from string (least efficient)
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $body->getContents());
        rewind($stream);

        return $stream;
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
}
