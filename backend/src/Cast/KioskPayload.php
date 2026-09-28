<?php

namespace App\Cast;

use App\Entity\Playlist;
use App\Entity\Screen;
use App\Entity\Source;
use App\Repository\SourceRepository;
use App\Source\SourceFetcher;
use App\Source\SourceViews;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What a screen shows now: the enabled panels of its playlist whose schedule is active in the screen's time zone,
 * source panels filled with the view of their source (skipped when it has nothing to show), and when to ask again
 * (reloadAt: next schedule change, a source's reloadAt, or 15 minutes at most).
 */
final class KioskPayload
{
    public const POLL_SECONDS = 60;
    private const MAX_RELOAD_SECONDS = 900;

    public function __construct(
        private readonly SourceRepository $sources,
        private readonly SourceFetcher $fetcher,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return array<string, mixed> */
    public function forScreen(Screen $screen, \DateTimeImmutable $now): array
    {
        $playlist = $screen->isEnabled() ? $screen->getPlaylist() : null;
        $built = $this->build($playlist?->getPanels() ?? [], $screen->getTimezone(), $now, false);

        return [
            'screen' => ['name' => $screen->getName(), 'orientation' => $screen->getOrientation(), 'timezone' => $screen->getTimezone(), 'locale' => $screen->getLocale(), 'enabled' => $screen->isEnabled()],
            'playlist' => null === $playlist ? null : self::style($playlist),
        ] + $built;
    }

    /**
     * Preview of panels being edited: every enabled panel, flagged "activeNow".
     *
     * @param list<array<string, mixed>> $panels normalised
     *
     * @return array<string, mixed>
     */
    public function preview(array $panels, string $timezone, \DateTimeImmutable $now): array
    {
        return $this->build($panels, $timezone, $now, true);
    }

    /** @return array{name: string, accent: string, theme: string} */
    public static function style(Playlist $playlist): array
    {
        return ['name' => $playlist->getName(), 'accent' => $playlist->getAccent(), 'theme' => $playlist->getTheme()];
    }

    /**
     * @param list<array<string, mixed>> $panels
     *
     * @return array<string, mixed>
     */
    private function build(array $panels, string $timezone, \DateTimeImmutable $now, bool $preview): array
    {
        $local = $now->setTimezone(new \DateTimeZone($timezone));
        $reloadAt = $local->modify('+'.self::MAX_RELOAD_SECONDS.' seconds');
        $next = Schedule::nextChange(array_values(array_filter($panels, static fn (array $p) => $p['enabled'])), $local);
        if (null !== $next && $next < $reloadAt) {
            $reloadAt = $next;
        }
        $sources = [];
        $shown = [];
        foreach ($panels as $panel) {
            if (!$panel['enabled']) {
                continue;
            }
            $active = Schedule::isActive($panel['schedule'], $local);
            if (!$active && !$preview) {
                continue;
            }
            $item = ['id' => $panel['id'], 'type' => $panel['type'], 'duration' => $panel['duration'], 'settings' => $panel['settings']];
            if ('source' === $panel['type']) {
                $source = $sources[$panel['settings']['sourceId']] ??= $this->sources->find($panel['settings']['sourceId']) ?? false;
                $payload = $source instanceof Source && $source->isEnabled() ? $this->fetcher->payload($source) : null;
                $data = SourceViews::extract($panel['settings']['view'], $payload);
                if (null === $data && !$preview) {
                    continue;
                }
                $item['settings'] = ['view' => $panel['settings']['view'], 'title' => $panel['settings']['title']];
                $item['data'] = $data;
                $sourceReload = SourceFetcher::reloadAt($payload);
                if (null !== $sourceReload && $sourceReload > $now && $sourceReload < $reloadAt) {
                    $reloadAt = $sourceReload;
                }
            }
            if ($preview) {
                $item['activeNow'] = $active;
                $item['schedule'] = $panel['schedule'];
            }
            $shown[] = $item;
        }
        $this->em->flush();

        return [
            'panels' => $shown,
            'generatedAt' => $now->format(\DATE_ATOM),
            'reloadAt' => $reloadAt->format(\DATE_ATOM),
            'pollSeconds' => self::POLL_SECONDS,
            'version' => substr(hash('sha256', json_encode($shown, \JSON_THROW_ON_ERROR)), 0, 16),
        ];
    }
}
