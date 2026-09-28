<?php

namespace App\Entity;

use App\Repository\ScreenRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Rocket\Core\Entity\TrackedTrait;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A screen (TV, tablet, kiosk) showing a playlist on /s/<token>. Only the SHA-256 hash of the token is stored:
 * the kiosk URL is shown once, when the token is created or rotated (or delivered to a paired screen).
 */
#[ORM\Entity(repositoryClass: ScreenRepository::class)]
class Screen
{
    public const ORIENTATIONS = ['landscape', 'portrait'];

    /** A screen polling at least every minute is "online" while seen within this delay. */
    public const ONLINE_SECONDS = 180;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(length: 64, unique: true, nullable: true)]
    private ?string $tokenHash = null;

    /** Last 4 characters of the token, to tell links apart. */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $tokenHint = null;

    #[ORM\Column(length: 16)]
    private string $orientation = 'landscape';

    #[ORM\Column(length: 64)]
    private string $timezone = 'Europe/Paris';

    #[ORM\Column(length: 16)]
    private string $locale = 'fr-FR';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Playlist $playlist = null;

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastUserAgent = null;

    use TrackedTrait;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);

        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): static
    {
        $this->location = null === $location || '' === trim($location) ? null : trim($location);

        return $this;
    }

    /** Issues a new token (≥ 256 bits, URL-safe, 43 characters) and returns it: the previous link stops working. */
    public function rotateToken(): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->tokenHash = self::hashToken($token);
        $this->tokenHint = substr($token, -4);

        return $token;
    }

    /** A known token (demo screen only). */
    public function useToken(string $token): void
    {
        $this->tokenHash = self::hashToken($token);
        $this->tokenHint = substr($token, -4);
    }

    public function revokeToken(): void
    {
        $this->tokenHash = null;
        $this->tokenHint = null;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function getTokenHint(): ?string
    {
        return $this->tokenHint;
    }

    public function hasToken(): bool
    {
        return null !== $this->tokenHash;
    }

    public function getOrientation(): string
    {
        return $this->orientation;
    }

    public function setOrientation(string $orientation): static
    {
        $this->orientation = $orientation;

        return $this;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): static
    {
        $this->timezone = $timezone;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getPlaylist(): ?Playlist
    {
        return $this->playlist;
    }

    public function setPlaylist(?Playlist $playlist): static
    {
        $this->playlist = $playlist;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function seen(\DateTimeImmutable $at, ?string $userAgent): void
    {
        $this->lastSeenAt = $at;
        $this->lastUserAgent = null === $userAgent ? null : mb_substr($userAgent, 0, 255);
    }

    public function getLastUserAgent(): ?string
    {
        return $this->lastUserAgent;
    }

    public function isOnline(\DateTimeImmutable $now): bool
    {
        return null !== $this->lastSeenAt && $now->getTimestamp() - $this->lastSeenAt->getTimestamp() <= self::ONLINE_SECONDS;
    }
}
