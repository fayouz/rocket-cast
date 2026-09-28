<?php

namespace App\Source\Type;

use App\Source\SourceException;
use App\Source\SourceHttp;
use App\Source\SourceTypeInterface;
use App\Source\SourceViews;

/**
 * Rocket Place: the "Domotique" values of a place (temperature, humidity, consumption…), read with an application
 * token of Rocket Place (rpl_…): GET {baseUrl}/api/places/{placeId}/domotique, acting as a user (X-Impersonate-User)
 * when set. Every item of every card of every enabled connector becomes a value.
 */
final class RocketPlaceSource implements SourceTypeInterface
{
    private const MAX_ITEMS = 24;

    public function __construct(private readonly SourceHttp $http)
    {
    }

    public function id(): string
    {
        return 'rocket_place';
    }

    public function name(): string
    {
        return 'Rocket Place';
    }

    public function icon(): string
    {
        return 'i-lucide-house-wifi';
    }

    public function description(): string
    {
        return 'Valeurs domotiques d’un lieu (température, humidité, consommation…) lues dans Rocket Place.';
    }

    public function capabilities(): array
    {
        return ['items'];
    }

    public function fields(): array
    {
        return [
            ['key' => 'baseUrl', 'label' => 'Adresse de l’API Rocket Place', 'type' => 'url', 'required' => true, 'placeholder' => 'https://place.example.org'],
            ['key' => 'placeId', 'label' => 'Identifiant du lieu', 'type' => 'text', 'required' => true, 'placeholder' => '0199…', 'help' => 'UUID du lieu (adresse de sa fiche dans Rocket Place).'],
            ['key' => 'token', 'label' => 'Jeton d’application Rocket Place', 'type' => 'password', 'secret' => true, 'required' => true, 'help' => 'Administration → Applications de Rocket Place (rpl_…).'],
            ['key' => 'impersonate', 'label' => 'Agir en tant que (e-mail)', 'type' => 'email', 'help' => 'Utilisateur de Rocket Place ayant accès au lieu, si le jeton peut agir pour un utilisateur.'],
            ['key' => 'filter', 'label' => 'Valeurs à afficher', 'type' => 'text', 'placeholder' => 'Température, Humidité', 'help' => 'Libellés séparés par des virgules (vide : toutes).'],
        ];
    }

    public function normaliseConfig(array $config): array
    {
        $placeId = strtolower(trim((string) ($config['placeId'] ?? '')));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $placeId)) {
            throw new SourceException('Identifiant du lieu : un UUID est attendu.');
        }
        $impersonate = trim((string) ($config['impersonate'] ?? ''));
        if ('' !== $impersonate && false === filter_var($impersonate, \FILTER_VALIDATE_EMAIL)) {
            throw new SourceException('« Agir en tant que » : adresse e-mail invalide.');
        }

        return [
            'baseUrl' => SourceHttp::baseUrl($config['baseUrl'] ?? '', 'Adresse de l’API Rocket Place'),
            'placeId' => $placeId,
            'impersonate' => $impersonate,
            'filter' => mb_substr(trim((string) ($config['filter'] ?? '')), 0, 500),
        ];
    }

    public function fetch(array $config, #[\SensitiveParameter] array $secrets): array
    {
        $token = trim($secrets['token'] ?? '');
        if ('' === $token) {
            throw new SourceException('Jeton d’application Rocket Place manquant.');
        }
        $headers = ['Authorization' => 'Bearer '.$token];
        if ('' !== ($config['impersonate'] ?? '')) {
            $headers['X-Impersonate-User'] = $config['impersonate'];
        }

        return self::normalise($this->http->getJson(\sprintf('%s/api/places/%s/domotique', $config['baseUrl'], $config['placeId']), $headers), (string) ($config['filter'] ?? ''));
    }

    /**
     * {sections: [{name, cards: [{title, items: [{label, value, unit?}]}], error}]} → {items}.
     *
     * @param array<mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function normalise(array $data, string $filter = ''): array
    {
        $wanted = array_filter(array_map(static fn (string $l) => mb_strtolower(trim($l)), explode(',', $filter)));
        $items = [];
        foreach (\is_array($data['sections'] ?? null) ? $data['sections'] : [] as $section) {
            foreach (\is_array($section['cards'] ?? null) ? $section['cards'] : [] as $card) {
                foreach (\is_array($card['items'] ?? null) ? $card['items'] : [] as $item) {
                    if (!\is_array($item)) {
                        continue;
                    }
                    $label = SourceViews::str($item['label'] ?? $item['name'] ?? null);
                    $value = $item['value'] ?? null;
                    $value = \is_bool($value) ? ($value ? 'Oui' : 'Non') : SourceViews::str($value);
                    if (null === $label || null === $value || ([] !== $wanted && !\in_array(mb_strtolower($label), $wanted, true))) {
                        continue;
                    }
                    $items[] = array_filter(['label' => mb_substr($label, 0, 80), 'value' => mb_substr($value, 0, 80), 'unit' => SourceViews::str($item['unit'] ?? null)], static fn ($v) => null !== $v);
                    if (\count($items) >= self::MAX_ITEMS) {
                        break 3;
                    }
                }
            }
        }

        return ['items' => $items];
    }
}
