<?php

namespace App\Entity;

use App\Repository\ListingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ListingRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'listings')]
class Listing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private ?string $title = null;

    /* shared-bedroom | private-room | entire-house | boarding-house | studio-loft | apartment */
    #[ORM\Column(length: 50)]
    private ?string $category = null;

    /* town / city, e.g. "Dumaguete City" */
    #[ORM\Column(length: 100)]
    private ?string $location = null;

    /* province, e.g. "Negros Oriental" — enables province-level filtering */
    #[ORM\Column(length: 100)]
    private ?string $province = null;

    #[ORM\Column(length: 255)]
    private ?string $exactAddress = null;

    /* monthly rate, whole pesos */
    #[ORM\Column]
    private ?int $price = null;

    #[ORM\Column]
    private int $bedrooms = 1;

    #[ORM\Column]
    private bool $hasParking = false;

    /* JSON array of amenity keys: wifi, aircon, pet-friendly, free-water, free-electricity, security */
    #[ORM\Column(type: Types::JSON)]
    private array $amenities = [];

    #[ORM\Column(length: 20)]
    private string $status = 'approved';

    /* public path under /public, e.g. "images/StudioForRent.png" */
    #[ORM\Column(length: 255)]
    private ?string $coverImage = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }
    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }
    public function setCategory(string $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }
    public function setLocation(string $location): static
    {
        $this->location = $location;
        return $this;
    }

    public function getProvince(): ?string
    {
        return $this->province;
    }
    public function setProvince(string $province): static
    {
        $this->province = $province;
        return $this;
    }

    public function getExactAddress(): ?string
    {
        return $this->exactAddress;
    }
    public function setExactAddress(string $exactAddress): static
    {
        $this->exactAddress = $exactAddress;
        return $this;
    }

    public function getPrice(): ?int
    {
        return $this->price;
    }
    public function setPrice(int $price): static
    {
        $this->price = $price;
        return $this;
    }

    public function getBedrooms(): int
    {
        return $this->bedrooms;
    }
    public function setBedrooms(int $bedrooms): static
    {
        $this->bedrooms = $bedrooms;
        return $this;
    }

    public function isHasParking(): bool
    {
        return $this->hasParking;
    }
    public function setHasParking(bool $hasParking): static
    {
        $this->hasParking = $hasParking;
        return $this;
    }

    /** @return string[] */
    public function getAmenities(): array
    {
        return $this->amenities;
    }
    /** @param string[] $amenities */
    public function setAmenities(array $amenities): static
    {
        $this->amenities = $amenities;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }
    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getCoverImage(): ?string
    {
        return $this->coverImage;
    }
    public function setCoverImage(string $coverImage): static
    {
        $this->coverImage = $coverImage;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }
    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}
