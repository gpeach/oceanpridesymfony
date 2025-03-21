<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class DropboxLinkHelper
{
    private HttpClientInterface $client;
    private string $token;

    public function __construct(HttpClientInterface $client, string $accessToken)
    {
        $this->client = $client;
        $this->token = $accessToken;
    }

    public function getPreviewLink(string $path): ?string
    {
        $endpoint = 'https://api.dropboxapi.com/2/sharing/create_shared_link_with_settings';
        $headers = [
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type' => 'application/json',
        ];

        $body = [
            'path' => '/' . ltrim($path, '/'),
            'settings' => ['requested_visibility' => 'public']
        ];

        try {
            $response = $this->client->request('POST', $endpoint, [
                'headers' => $headers,
                'json' => $body,
            ]);

            $data = $response->toArray();

            // 🔍 Log API response
//            dump($data);

            if (!isset($data['url'])) {
                dump('Error: Dropbox did not return a URL');
                return null;
            }

            return str_replace(['?dl=0', '&dl=0'], ['?raw=1', '&raw=1'], $data['url']);

        } catch (\Exception $e) {
            dump('Dropbox API Error: ' . $e->getMessage());
            return null;
        }
    }
}
