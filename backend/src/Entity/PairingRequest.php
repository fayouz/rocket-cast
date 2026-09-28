<?php

namespace App\Entity;

use App\Repository\PairingRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A fresh screen asking to be linked (/pair): it shows a short code, typed by an administrator, and polls with a
 * device secret (only its hash is stored) until the kiosk token is delivered, once. The token waits sealed.
 */
#[ORM\Entity(repositoryClass: PairingRequestRepository::class)]
#[ORM\Index(name: 'idx_pairing_code', columns: ['code'])]
class PairingRequest
{
    public const TTL_SECONDS = 900;
    /** No 0/O/1/I/L: read on a TV from across the room. */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 6)]
    private string $code;

    #[ORM\Column(length: 64, unique: true)]
    private string $secretHash;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Screen $screen = null;

    /** Kiosk token sealed until the screen picks it up. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $sealedToken = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent = null;

    /** @param string $secret device secret, returned to the screen only */
    public function __construct(\DateTimeImmutable $now, string $secret, ?string $userAgent)
    {
        $this->id = Uuid::v7();
        $this->code = self::newCode();
        $this->secretHash = hash('sha256', $secret);
        $this->expiresAt = $now->modify('+'.self::TTL_SECONDS.' seconds');
        $this->userAgent = null === $userAgent ? null : mb_substr($userAgent, 0, 255);
    }

    public static function newCode(): string
    {
        $code = '';
        for ($i = 0; $i < 6; ++$i) {
            $code .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    /** "k7f-3qx " → "K7F3QX". */
    public static function normaliseCode(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function getScreen(): ?Screen
    {
        return $this->screen;
    }

    public function getSealedToken(): ?string
    {
        return $this->sealedToken;
    }

    public function pair(Screen $screen, string $sealedToken): void
    {
        $this->screen = $screen;
        $this->sealedToken = $sealedToken;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }
}
