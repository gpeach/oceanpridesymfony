<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;

#[ORM\Entity]
class GalleryImage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $filePath = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $posterImagePath = null;

    private ?File $file = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $shareLink = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $type = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cloudStorageType = null;

    // === Getters and Setters ===

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    public function setFilePath(?string $filePath): self
    {
        $this->filePath = $filePath;
        return $this;
    }

    public function getPosterImagePath(): ?string
    {
        return $this->posterImagePath;
    }

    public function setPosterImagePath(?string $posterImagePath): self
    {
        $this->posterImagePath = $posterImagePath;
        return $this;
    }

    public function getFile(): ?File
    {
        return $this->file;
    }

    public function setFile(?File $file): self
    {
        $this->file = $file;

        if ($file !== null) {
            $this->updatedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getShareLink(): ?string
    {
        return $this->shareLink;
    }

    public function setShareLink(?string $link): self
    {
        $this->shareLink = $link;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getCloudStorageType(): ?string
    {
        return $this->cloudStorageType;
    }

    public function setCloudStorageType(?string $cloudStorageType): static
    {
        $this->cloudStorageType = $cloudStorageType;

        return $this;
    }
}
