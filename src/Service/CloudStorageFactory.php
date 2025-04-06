<?php

namespace App\Service;

use Aws\S3\S3Client;
use Spatie\Dropbox\Client as DropboxClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

class CloudStorageFactory
{
    public function __construct(
        private string $cloudStorageDriver,
        private string $dropboxClientId,
        private string $dropboxClientSecret,
        private string $dropboxRefreshToken,
        private string $awsS3Bucket,
        private LoggerInterface $logger,
        private S3Client $s3,
        private HttpClientInterface $httpClient
    ) {}

    public function getStorage(): CloudStorageInterface
    {
        return match ($this->cloudStorageDriver) {
            's3' => new S3StorageService($this->s3, $this->awsS3Bucket, $this->logger),
            'dropbox' => new DropboxStorageService($this->getFreshDropboxClient()),
            default => throw new \RuntimeException("Unsupported cloud driver: {$this->cloudStorageDriver}"),
        };
    }

    private function getFreshDropboxClient(): DropboxClient
    {
        $response = $this->httpClient->request('POST', 'https://api.dropbox.com/oauth2/token', [
            'body' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->dropboxRefreshToken,
                'client_id' => $this->dropboxClientId,
                'client_secret' => $this->dropboxClientSecret,
            ],
        ]);

        $data = $response->toArray();

        if (!isset($data['access_token'])) {
            throw new \RuntimeException('Failed to refresh Dropbox access token.');
        }

        return new DropboxClient($data['access_token']);
    }
}
