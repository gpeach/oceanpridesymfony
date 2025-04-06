<?php

namespace App\Service;

use Aws\S3\S3Client;
use Aws\S3\PostObjectV4;
use Aws\S3\MultipartUploader;
use Aws\Exception\MultipartUploadException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

class S3StorageService implements CloudStorageInterface
{

    public function __construct(
        private S3Client $s3,
        private string $bucket,
        private LoggerInterface $logger
    ) {
    }

    //think we are deprecating this in favor of js, at least for s3
    public function upload(string $cloudPath, string $localPath): void
    {
        if (!file_exists($localPath) || !is_readable($localPath)) {
            throw new \RuntimeException("Invalid or unreadable file: $localPath");
        }

        $this->logger->info('[S3 MULTIPART UPLOAD] starting upload: ' . $localPath);

        try {
            $uploader = new MultipartUploader($this->s3, $localPath, [
                'bucket' => $_ENV['AWS_S3_BUCKET'],
                'key' => $cloudPath,
            ]);
            $result = $uploader->upload();
            $this->logger->info('[S3 MULTIPART UPLOAD] Upload complete: ' . $result['ObjectURL']);
        } catch (MultipartUploadException $e) {
            $this->logger->error('[S3 MULTIPART UPLOAD] Multipart upload failed: ' . $e->getMessage());
            throw new \RuntimeException('Upload failed: ' . $e->getMessage());
        }
    }

    public function createPresignedPutUrl(string $filename, string $mimeType): array
    {
        $folder = $_ENV['CLOUD_FOLDER'];
        $path = $folder . '/' . $filename;

        $cmd = $this->s3->getCommand('PutObject', [
            'Bucket' => $this->bucket,
            'Key' => $path,
            'ContentType' => $mimeType
        ]);

        $request = $this->s3->createPresignedRequest($cmd, '+10 minutes');

        return [
            'url' => (string)$request->getUri(),
            'filename' => $filename,
        ];
    }


//    public function upload(string $cloudPath, string $localPath): void
//    {
//        if (!file_exists($localPath) || !is_readable($localPath)) {
//            throw new \RuntimeException("Invalid or unreadable file: $localPath");
//        }
//
//        $this->s3->putObject([
//            'Bucket' => $this->bucket,
//            'Key' => $cloudPath,
//            'SourceFile' => $localPath,
//        ]);
//
//        // Generate and cache the poster image
//        //$posterImagePath = $this->generateAndCachePosterImage($cloudPath);
//    }


    public function download(string $cloudPath): string
    {
        $result = $this->s3->getObject([
            'Bucket' => $this->bucket,
            'Key' => $cloudPath,
        ]);

        return (string)$result['Body'];
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
            'Key' => $cloudPath,
            'ResponseContentDisposition' => 'inline',
            'response-content-type' => 'video/mp4',
        ]);

        $request = $this->s3->createPresignedRequest($cmd, $expires);
        return (string)$request->getUri();
    }
}
