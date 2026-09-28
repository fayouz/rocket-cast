<?php

namespace App\Source\Type;

use App\Source\SourceException;
use App\Source\SourceHttp;
use App\Source\SourceTypeInterface;
use App\Source\SourceViews;

/**
 * Any JSON API: values picked by path ("current.temperature", "items.0.name"), one per line of "fields":
 * "Libellé | chemin | unité". An optional Authorization header is sealed with the credentials.
 */
final class WebJsonSource implements SourceTypeInterface
{
    private const MAX_FIELDS = 24;

    public function __construct(private readonly SourceHttp $http)
    {
    }

    public function id(): string
    {
        return 'web_json';
    }

    public function name(): string
    {
        return 'Web JSON';
    }

    public function icon(): string
    {
        return 'i-lucide-braces';
    }

    public function description(): string
    {
        return 'N’importe quelle API JSON : des valeurs choisies par leur chemin, ou un texte.';
    }

    public function capabilities(): array
    {
        return ['items', 'text'];
    }

    public function fields(): array
    {
        return [
            ['key' => 'url', 'label' => 'Adresse (GET, JSON)', 'type' => 'url', 'required' => true, 'placeholder' => 'https://api.example.org/status'],
            ['key' => 'title', 'label' => 'Titre affiché', 'type' => 'text'],
            ['key' => 'fields', 'label' => 'Valeurs', 'type' => 'textarea', 'placeholder' => "Température | current.temperature | °C\nVisiteurs | stats.visitors", 'help' => 'Une valeur par ligne : Libellé | chemin | unité (facultative).'],
            ['key' => 'textPath', 'label' => 'Chemin du texte', 'type' => 'text', 'placeholder' => 'message.body', 'help' => 'Pour la vue « Texte ».'],
            ['key' => 'authorization', 'label' => 'En-tête Authorization', 'type' => 'password', 'secret' => true, 'help' => 'Facultatif, par exemple « Bearer … ».'],
        ];
    }

    public function normaliseConfig(array $config): array
    {
        $fields = mb_substr(trim((string) ($config['fields'] ?? '')), 0, 4000);
        self::parseFields($fields);
        $textPath = trim((string) ($config['textPath'] ?? ''));
        if ('' === $fields && '' === $textPath) {
            throw new SourceException('Indiquez au moins une valeur ou le chemin d’un texte.');
        }

        return [
            'url' => SourceHttp::baseUrl($config['url'] ?? '', 'Adresse'),
            'title' => mb_substr(trim((string) ($config['title'] ?? '')), 0, 120),
            'fields' => $fields,
            'textPath' => mb_substr($textPath, 0, 200),
        ];
    }

    public function fetch(array $config, #[\SensitiveParameter] array $secrets): array
    {
        $headers = '' !== ($secrets['authorization'] ?? '') ? ['Authorization' => $secrets['authorization']] : [];
        $data = $this->http->getJson((string) $config['url'], $headers);
        $items = [];
        foreach (self::parseFields((string) ($config['fields'] ?? '')) as [$label, $path, $unit]) {
            $value = self::at($data, $path);
            $value = \is_bool($value) ? ($value ? 'Oui' : 'Non') : SourceViews::str($value);
            if (null !== $value) {
                $items[] = array_filter(['label' => $label, 'value' => mb_substr($value, 0, 120), 'unit' => $unit], static fn ($v) => null !== $v);
            }
        }
        $text = '' === ($config['textPath'] ?? '') ? null : SourceViews::str(self::at($data, (string) $config['textPath']));

        return array_filter([
            'title' => '' === ($config['title'] ?? '') ? null : $config['title'],
            'items' => $items,
            'text' => null === $text ? null : mb_substr($text, 0, 5000),
        ], static fn ($v) => null !== $v);
    }

    /** @return list<array{0: string, 1: string, 2: ?string}> */
    public static function parseFields(string $fields): array
    {
        $parsed = [];
        foreach (preg_split('/\R/', $fields) ?: [] as $n => $line) {
            if ('' === trim($line)) {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            if (\count($parts) < 2 || '' === $parts[0] || !preg_match('/^[A-Za-z0-9_\-]+(\.[A-Za-z0-9_\-]+)*$/', $parts[1])) {
                throw new SourceException(\sprintf('Valeurs, ligne %d : « Libellé | chemin | unité » attendu (chemin : clés séparées par des points).', $n + 1));
            }
            $parsed[] = [mb_substr($parts[0], 0, 80), $parts[1], '' === ($parts[2] ?? '') ? null : mb_substr($parts[2], 0, 16)];
        }
        if (\count($parsed) > self::MAX_FIELDS) {
            throw new SourceException(\sprintf('%d valeurs au plus.', self::MAX_FIELDS));
        }

        return $parsed;
    }

    /** @param array<mixed> $data */
    public static function at(array $data, string $path): mixed
    {
        $node = $data;
        foreach (explode('.', $path) as $key) {
            if (!\is_array($node) || !\array_key_exists($key, $node)) {
                return null;
            }
            $node = $node[$key];
        }

        return $node;
    }
}
