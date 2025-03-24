<?php

namespace App\Service;

use Spatie\Dropbox\Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DropboxClientFactory
{
    private string $clientId;
    private string $clientSecret;
    private string $refreshToken;
    private HttpClientInterface $http;

    public function __construct(string $clientId, string $clientSecret, string $refreshToken, HttpClientInterface $http)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->refreshToken = $refreshToken;
        $this->http = $http;
    }

    public function create(): Client
    {
        $response = $this->http->request('POST', 'https://api.dropboxapi.com/oauth2/token', [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->refreshToken,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ],
        ]);

        $data = $response->toArray();

        return new Client($data['access_token']);
    }
}
