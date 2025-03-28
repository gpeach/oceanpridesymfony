<?php

namespace App\Service;

interface CloudStorageInterface
{
    public function download(string $cloudPath): string;

    public function downloadStream(string $cloudPath): mixed;

    public function upload(string $cloudPath, string $localPath): void;

    public function list(string $directory): array;

    public function delete(string $path): void;

    public function getSignedUrl(string $cloudPath, string $expires = '+1 hour'): string;

    public function cachePosterImage(string $cloudPath, string $posterImagePath): void;

    public function getCachedPosterImage(string $cloudPath): ?string;
}
