<?php

namespace App\Cast;

use App\Entity\Playlist;
use App\Entity\Screen;
use App\Entity\Source;
use App\Secret\SecretException;
use App\Secret\SecretStoreInterface;
use App\Source\SourceRegistry;
use App\Source\SourceViews;

/** JSON representations of the administration API. */
final class CastViews
{
    public function __construct(private readonly SourceRegistry $registry, private readonly SecretStoreInterface $secretStore)
    {
    }

    /** @return array<string, mixed> */
    public function screen(Screen $s, \DateTimeImmutable $now): array
    {
        return [
            'id' => $s->getId()->toRfc4122(),
            'name' => $s->getName(),
            'location' => $s->getLocation(),
            'orientation' => $s->getOrientation(),
            'timezone' => $s->getTimezone(),
            'locale' => $s->getLocale(),
            'enabled' => $s->isEnabled(),
            'playlist' => null === $s->getPlaylist() ? null : ['id' => $s->getPlaylist()->getId()->toRfc4122(), 'name' => $s->getPlaylist()->getName()],
            'hasToken' => $s->hasToken(),
            'tokenHint' => $s->getTokenHint(),
            'lastSeenAt' => $s->getLastSeenAt()?->format(\DATE_ATOM),
            'online' => $s->isOnline($now),
            'lastUserAgent' => $s->getLastUserAgent(),
        ] + self::tracking($s);
    }

    /** @return array<string, mixed> */
    public function playlist(Playlist $p, int $screens, bool $withPanels = true): array
    {
        $data = [
            'id' => $p->getId()->toRfc4122(),
            'name' => $p->getName(),
            'description' => $p->getDescription(),
            'accent' => $p->getAccent(),
            'theme' => $p->getTheme(),
            'panelCount' => \count($p->getPanels()),
            'duration' => array_sum(array_map(static fn (array $panel) => $panel['enabled'] ? $panel['duration'] : 0, $p->getPanels())),
            'screenCount' => $screens,
        ];
        if ($withPanels) {
            $data['panels'] = $p->getPanels();
        }

        return $data + self::tracking($p);
    }

    /** @return array<string, mixed> */
    public function source(Source $s, bool $admin): array
    {
        $type = $this->registry->has($s->getType()) ? $this->registry->get($s->getType()) : null;
        $data = [
            'id' => $s->getId()->toRfc4122(),
            'name' => $s->getName(),
            'type' => $s->getType(),
            'typeName' => $type?->name() ?? $s->getType(),
            'icon' => $type?->icon() ?? 'i-lucide-plug',
            'capabilities' => array_map(static fn (string $v) => ['id' => $v, 'label' => SourceViews::VIEWS[$v] ?? $v], $type?->capabilities() ?? []),
            'enabled' => $s->isEnabled(),
            'refreshSeconds' => $s->getRefreshSeconds(),
            'fetchedAt' => $s->getFetchedAt()?->format(\DATE_ATOM),
            'attemptedAt' => $s->getAttemptedAt()?->format(\DATE_ATOM),
            'lastError' => $s->getLastError(),
        ];
        if ($admin) {
            $data['config'] = $s->getConfig();
            // Which secret fields are set, never their values.
            try {
                $set = $this->secretStore->open($s->getSealedSecrets());
                $data['secretsReadable'] = true;
            } catch (SecretException) {
                $set = [];
                $data['secretsReadable'] = false;
            }
            $data['secrets'] = [];
            foreach ($type?->fields() ?? [] as $field) {
                if ($field['secret'] ?? false) {
                    $data['secrets'][$field['key']] = '' !== ($set[$field['key']] ?? '');
                }
            }
        }

        return $data + self::tracking($s);
    }

    /** @return array<string, mixed> */
    private static function tracking(Screen|Playlist|Source $e): array
    {
        return [
            'createdAt' => $e->getCreatedAt()?->format(\DATE_ATOM),
            'updatedAt' => $e->getUpdatedAt()?->format(\DATE_ATOM),
            'createdBy' => $e->getCreatedBy(),
            'updatedBy' => $e->getUpdatedBy(),
        ];
    }
}
