<?php

namespace App\Entity;

use App\Repository\PlaylistRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Rocket\Core\Entity\TrackedTrait;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * An ordered list of panels shown in turn by the screens using it. The panels are a JSON list, normalised and
 * validated by App\Cast\Panels (type, duration, schedule, settings of the type).
 */
#[ORM\Entity(repositoryClass: PlaylistRepository::class)]
class Playlist
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: Types::JSON)]
    private array $panels = [];

    /** Accent colour of the kiosk (hex). */
    #[ORM\Column(length: 7)]
    private string $accent = '#0ea5e9';

    /** "dark" or "light" kiosk theme. */
    #[ORM\Column(length: 8)]
    private string $theme = 'dark';

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = null === $description || '' === trim($description) ? null : trim($description);

        return $this;
    }

    /** @return list<array<string, mixed>> */
    public function getPanels(): array
    {
        return $this->panels;
    }

    /** @param list<array<string, mixed>> $panels already normalised */
    public function setPanels(array $panels): static
    {
        $this->panels = array_values($panels);

        return $this;
    }

    public function getAccent(): string
    {
        return $this->accent;
    }

    public function setAccent(string $accent): static
    {
        $this->accent = $accent;

        return $this;
    }

    public function getTheme(): string
    {
        return $this->theme;
    }

    public function setTheme(string $theme): static
    {
        $this->theme = $theme;

        return $this;
    }
}
