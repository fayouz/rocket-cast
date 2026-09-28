<?php

namespace App\Entity;

use App\Repository\SourceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Rocket\Core\Entity\TrackedTrait;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A data source feeding "source" panels: an integration (App\Source\SourceTypeInterface) with its settings, its
 * credentials (sealed by App\Secret\SecretStoreInterface, never returned by the API) and the last payload fetched,
 * kept when the source is unreachable.
 */
#[ORM\Entity(repositoryClass: SourceRepository::class)]
class Source
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(length: 32)]
    private string $type = '';

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $config = [];

    #[ORM\Column(type: Types::TEXT, options: ['default' => ''])]
    private string $sealedSecrets = '';

    /** Seconds a fetched payload is reused. */
    #[ORM\Column]
    private int $refreshSeconds = 300;

    #[ORM\Column]
    private bool $enabled = true;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $payload = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $fetchedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $attemptedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

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

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        return $this->config;
    }

    /** @param array<string, mixed> $config */
    public function setConfig(array $config): static
    {
        $this->config = $config;

        return $this;
    }

    public function getSealedSecrets(): string
    {
        return $this->sealedSecrets;
    }

    public function setSealedSecrets(string $sealedSecrets): static
    {
        $this->sealedSecrets = $sealedSecrets;

        return $this;
    }

    public function getRefreshSeconds(): int
    {
        return $this->refreshSeconds;
    }

    public function setRefreshSeconds(int $refreshSeconds): static
    {
        $this->refreshSeconds = $refreshSeconds;

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

    /** @return array<string, mixed>|null */
    public function getPayload(): ?array
    {
        return $this->payload;
    }

    public function getFetchedAt(): ?\DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    public function getAttemptedAt(): ?\DateTimeImmutable
    {
        return $this->attemptedAt;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /** @param array<string, mixed> $payload */
    public function fetched(array $payload, \DateTimeImmutable $at): void
    {
        $this->payload = $payload;
        $this->fetchedAt = $at;
        $this->attemptedAt = $at;
        $this->lastError = null;
    }

    public function failed(string $error, \DateTimeImmutable $at): void
    {
        $this->attemptedAt = $at;
        $this->lastError = mb_substr($error, 0, 1000);
    }

    /** Forgets the payload (settings changed). */
    public function resetPayload(): void
    {
        $this->payload = null;
        $this->fetchedAt = null;
        $this->attemptedAt = null;
        $this->lastError = null;
    }
}
