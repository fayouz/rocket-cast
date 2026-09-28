<?php

namespace App\Cast;

use App\Repository\SourceRepository;
use App\Source\SourceRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Normalises and validates the panels of a playlist: {id, type, duration (5–3600 s), enabled, schedule, settings}.
 * Unknown settings are dropped, so a panel only stores what its type uses.
 */
final class Panels
{
    public const TYPES = [
        'welcome' => 'Message d’accueil',
        'clock' => 'Horloge',
        'weather' => 'Météo',
        'wifi' => 'Wi-Fi',
        'checkout' => 'Départ',
        'image' => 'Image',
        'richtext' => 'Texte',
        'qrcode' => 'QR code',
        'source' => 'Source',
    ];

    public const MAX_PANELS = 50;

    public function __construct(
        private readonly SourceRepository $sources,
        private readonly SourceRegistry $registry,
        #[Autowire(env: 'CAST_CLOUD_URL')] private readonly string $cloudUrl = '',
    ) {
    }

    /**
     * @param mixed $panels as submitted
     *
     * @return list<array<string, mixed>>
     *
     * @throws PanelException
     */
    public function normalise(mixed $panels): array
    {
        if (!\is_array($panels) || !array_is_list($panels)) {
            throw new PanelException('« panels » doit être une liste.');
        }
        if (\count($panels) > self::MAX_PANELS) {
            throw new PanelException(\sprintf('%d panneaux au plus par playlist.', self::MAX_PANELS));
        }
        $normalised = [];
        $ids = [];
        foreach ($panels as $i => $panel) {
            $n = $i + 1;
            if (!\is_array($panel)) {
                throw new PanelException(\sprintf('Panneau %d : objet attendu.', $n));
            }
            $type = (string) ($panel['type'] ?? '');
            if (!isset(self::TYPES[$type])) {
                throw new PanelException(\sprintf('Panneau %d : type inconnu « %s ».', $n, $type));
            }
            $id = \is_string($panel['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $panel['id']) && !isset($ids[$panel['id']])
                ? $panel['id'] : substr(Uuid::v4()->toBase58(), 0, 12);
            $ids[$id] = true;
            $duration = $panel['duration'] ?? 15;
            if (!\is_int($duration) || $duration < 5 || $duration > 3600) {
                throw new PanelException(\sprintf('Panneau %d : durée entre 5 et 3600 secondes.', $n));
            }
            try {
                $settings = $this->settings($type, \is_array($panel['settings'] ?? null) ? $panel['settings'] : []);
                $schedule = self::schedule($panel['schedule'] ?? null);
            } catch (PanelException $e) {
                throw new PanelException(\sprintf('Panneau %d (%s) : %s', $n, self::TYPES[$type], $e->getMessage()));
            }
            $normalised[] = [
                'id' => $id,
                'type' => $type,
                'duration' => $duration,
                'enabled' => (bool) ($panel['enabled'] ?? true),
                'schedule' => $schedule,
                'settings' => $settings,
            ];
        }

        return $normalised;
    }

    /** @return array{days: list<int>, from: string, until: string}|null */
    public static function schedule(mixed $schedule): ?array
    {
        if (null === $schedule || [] === $schedule) {
            return null;
        }
        if (!\is_array($schedule)) {
            throw new PanelException('programmation invalide.');
        }
        $days = $schedule['days'] ?? [1, 2, 3, 4, 5, 6, 7];
        if (!\is_array($days) || [] === $days) {
            throw new PanelException('choisissez au moins un jour.');
        }
        foreach ($days as $day) {
            if (!\is_int($day) || $day < 1 || $day > 7) {
                throw new PanelException('jours de 1 (lundi) à 7 (dimanche).');
            }
        }
        $days = array_values(array_unique($days));
        sort($days);
        $from = (string) ($schedule['from'] ?? '00:00');
        $until = (string) ($schedule['until'] ?? '00:00');
        foreach ([$from, $until] as $k => $time) {
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) && !(1 === $k && '24:00' === $time)) {
                throw new PanelException('heures au format HH:MM.');
            }
        }
        if ($from === $until) {
            if ('00:00' !== $from) {
                throw new PanelException('l’heure de fin doit différer de l’heure de début.');
            }
            // Whole days: 00:00 → 00:00 of the next day, stored as 00:00 → 24:00.
            $until = '24:00';
        }

        return ['days' => $days, 'from' => $from, 'until' => $until];
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private function settings(string $type, array $s): array
    {
        return match ($type) {
            'welcome' => ['title' => self::text($s, 'title', 120, true), 'text' => self::text($s, 'text', 2000)],
            'clock' => ['label' => self::text($s, 'label', 120), 'showDate' => (bool) ($s['showDate'] ?? true), 'showSeconds' => (bool) ($s['showSeconds'] ?? false)],
            'weather' => [
                'label' => self::text($s, 'label', 120, true),
                'latitude' => self::number($s, 'latitude', -90, 90),
                'longitude' => self::number($s, 'longitude', -180, 180),
                'days' => max(1, min(5, (int) ($s['days'] ?? 3))),
            ],
            'wifi' => [
                'ssid' => self::text($s, 'ssid', 64, true),
                'password' => self::text($s, 'password', 128),
                'security' => \in_array($s['security'] ?? 'WPA', ['WPA', 'WEP', 'nopass'], true) ? ($s['security'] ?? 'WPA') : 'WPA',
                'showQr' => (bool) ($s['showQr'] ?? true),
            ],
            'checkout' => ['time' => self::time($s, 'time'), 'text' => self::text($s, 'text', 2000)],
            'image' => ['url' => $this->imageUrl($s['url'] ?? ''), 'fit' => 'contain' === ($s['fit'] ?? 'cover') ? 'contain' : 'cover', 'caption' => self::text($s, 'caption', 200)],
            'richtext' => ['title' => self::text($s, 'title', 120), 'text' => self::text($s, 'text', 5000, true)],
            'qrcode' => ['value' => self::text($s, 'value', 1000, true), 'title' => self::text($s, 'title', 120), 'caption' => self::text($s, 'caption', 300)],
            'source' => $this->sourceSettings($s),
        };
    }

    /** @param array<string, mixed> $s @return array<string, mixed> */
    private function sourceSettings(array $s): array
    {
        $id = (string) ($s['sourceId'] ?? '');
        $source = Uuid::isValid($id) ? $this->sources->find($id) : null;
        if (null === $source) {
            throw new PanelException('source introuvable.');
        }
        $view = (string) ($s['view'] ?? '');
        $capabilities = $this->registry->has($source->getType()) ? $this->registry->get($source->getType())->capabilities() : [];
        if (!\in_array($view, $capabilities, true)) {
            throw new PanelException(\sprintf('la source « %s » ne propose pas la vue « %s ».', $source->getName(), $view));
        }

        return ['sourceId' => $source->getId()->toRfc4122(), 'view' => $view, 'title' => self::text($s, 'title', 120)];
    }

    private function imageUrl(mixed $url): string
    {
        $url = trim((string) $url);
        $cloud = rtrim($this->cloudUrl, '/');
        $valid = (bool) preg_match('#^https://[^\s]+$#i', $url) || ('' !== $cloud && str_starts_with($url, $cloud.'/') && !preg_match('#\s#', $url));
        if (!$valid || \strlen($url) > 2000) {
            throw new PanelException('image : une adresse https:// (ou un lien Rocket Cloud) est attendue.');
        }

        return $url;
    }

    /** @param array<string, mixed> $s */
    private static function text(array $s, string $key, int $max, bool $required = false): string
    {
        $value = $s[$key] ?? '';
        if (!\is_string($value) && !\is_int($value)) {
            throw new PanelException(\sprintf('« %s » doit être un texte.', $key));
        }
        $value = trim((string) $value);
        if ($required && '' === $value) {
            throw new PanelException(\sprintf('« %s » est obligatoire.', $key));
        }
        if (mb_strlen($value) > $max) {
            throw new PanelException(\sprintf('« %s » : %d caractères au plus.', $key, $max));
        }

        return $value;
    }

    /** @param array<string, mixed> $s */
    private static function number(array $s, string $key, float $min, float $max): float
    {
        $value = $s[$key] ?? null;
        if (!is_numeric($value) || (float) $value < $min || (float) $value > $max) {
            throw new PanelException(\sprintf('« %s » entre %s et %s.', $key, $min, $max));
        }

        return round((float) $value, 4);
    }

    /** @param array<string, mixed> $s */
    private static function time(array $s, string $key): string
    {
        $value = (string) ($s[$key] ?? '');
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
            throw new PanelException(\sprintf('« %s » au format HH:MM.', $key));
        }

        return $value;
    }
}
