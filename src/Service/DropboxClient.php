<?php

namespace App\Service;

use Spatie\Dropbox\Client;

class DropboxClient extends Client
{
    public function __construct(string $accessToken)
    {
        parent::__construct($accessToken);
    }
}
