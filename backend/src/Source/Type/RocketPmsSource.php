<?php

namespace App\Source\Type;

use App\Source\SourceException;
use App\Source\SourceHttp;
use App\Source\SourceTypeInterface;
use App\Source\SourceViews;

/**
 * Rocket PMS: the welcome book of a property and its guests, read from the public TV endpoint of the property
 * (GET {baseUrl}/api/public/tv/{tvToken}?lang=…, the token of the "écran TV" link of the welcome book).
 * The token is a credential: sealed in the database, never returned.
 */
final class RocketPmsSource implements SourceTypeInterface
{
    public function __construct(private readonly SourceHttp $http)
    {
    }

    public function id(): string
    {
        return 'rocket_pms';
    }

    public function name(): string
    {
        return 'Rocket PMS';
    }

    public function icon(): string
    {
        return 'i-lucide-hotel';
    }

    public function description(): string
    {
        return 'Livret d’accueil d’un logement : message d’accueil, prénom du voyageur présent, prochaine arrivée, Wi-Fi, départ.';
    }

    public function capabilities(): array
    {
        return ['welcome', 'guest', 'next_arrival', 'wifi', 'checkout', 'rules', 'tips', 'contacts'];
    }

    public function fields(): array
    {
        return [
            ['key' => 'baseUrl', 'label' => 'Adresse de l’API Rocket PMS', 'type' => 'url', 'required' => true, 'placeholder' => 'https://pms.example.org', 'help' => 'Adresse de l’API (le front la relaie aussi sous /api).'],
            ['key' => 'tvToken', 'label' => 'Jeton du lien « écran TV »', 'type' => 'password', 'secret' => true, 'required' => true, 'help' => 'Livret d’accueil du logement → lien écran TV : la partie après /tv/ (43 caractères).'],
            ['key' => 'lang', 'label' => 'Langue', 'type' => 'select', 'options' => [
                ['label' => 'Français', 'value' => 'fr'], ['label' => 'English', 'value' => 'en'], ['label' => 'Español', 'value' => 'es'],
                ['label' => 'Deutsch', 'value' => 'de'], ['label' => 'Italiano', 'value' => 'it'],
            ]],
        ];
    }

    public function normaliseConfig(array $config): array
    {
        $lang = (string) ($config['lang'] ?? 'fr');

        return [
            'baseUrl' => SourceHttp::baseUrl($config['baseUrl'] ?? '', 'Adresse de l’API Rocket PMS'),
            'lang' => preg_match('/^[a-z]{2}$/', $lang) ? $lang : 'fr',
        ];
    }

    public function fetch(array $config, #[\SensitiveParameter] array $secrets): array
    {
        $token = $secrets['tvToken'] ?? '';
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            throw new SourceException('Jeton « écran TV » manquant ou invalide (43 caractères attendus).');
        }
        $tv = $this->http->getJson(\sprintf('%s/api/public/tv/%s?lang=%s', $config['baseUrl'], $token, rawurlencode((string) ($config['lang'] ?? 'fr'))));

        return self::normalise($tv);
    }

    /**
     * Payload of GET /api/public/tv/{token} (Rocket PMS) → normalised payload.
     *
     * @param array<mixed> $tv
     *
     * @return array<string, mixed>
     */
    public static function normalise(array $tv): array
    {
        $content = \is_array($tv['content'] ?? null) ? $tv['content'] : [];
        $guest = \is_array($tv['guest'] ?? null) ? $tv['guest'] : null;
        $ssid = SourceViews::str($content['wifiSsid'] ?? null);

        return array_filter([
            'title' => SourceViews::str($tv['property'] ?? null),
            'welcomeText' => SourceViews::str($content['welcomeText'] ?? null),
            'checkoutInfo' => SourceViews::str($content['checkoutInfo'] ?? null),
            'houseRules' => SourceViews::str($content['houseRules'] ?? null),
            'localTips' => SourceViews::str($content['localTips'] ?? null),
            'contacts' => SourceViews::str($content['contacts'] ?? null),
            'wifi' => null === $ssid ? null : ['ssid' => $ssid, 'password' => SourceViews::str($content['wifiPassword'] ?? null)],
            'guest' => null === $guest ? null : [
                'firstName' => SourceViews::str($guest['firstName'] ?? null),
                'departure' => SourceViews::str($guest['departure'] ?? null),
                'checkOut' => SourceViews::str($guest['checkOut'] ?? null),
            ],
            'nextArrival' => null === SourceViews::str($tv['nextArrival'] ?? null) ? null
                : ['date' => SourceViews::str($tv['nextArrival']), 'at' => SourceViews::str($tv['nextArrivalAt'] ?? null)],
            'reloadAt' => SourceViews::str($tv['reloadAt'] ?? null),
            'latitude' => is_numeric($tv['latitude'] ?? null) ? (float) $tv['latitude'] : null,
            'longitude' => is_numeric($tv['longitude'] ?? null) ? (float) $tv['longitude'] : null,
            'accent' => \is_array($tv['style'] ?? null) ? SourceViews::str($tv['style']['accent'] ?? null) : null,
        ], static fn ($v) => null !== $v);
    }
}
