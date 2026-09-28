<?php

namespace App\Source;

/**
 * What a "source" panel shows of a source payload. Normalised payload keys (all optional):
 * - title (string), welcomeText, checkoutInfo, houseRules, localTips, contacts, text (strings);
 * - guest: {firstName, arrival?, departure?, checkOut?} (current guest), nextArrival: {date, at?};
 * - wifi: {ssid, password?}; items: list<{label, value, unit?}>;
 * - reloadAt (ATOM): the screens fetch the payload again at that time (e.g. 30 minutes before a check-in).
 */
final class SourceViews
{
    public const VIEWS = [
        'welcome' => 'Message d’accueil (avec le prénom du voyageur)',
        'guest' => 'Voyageur présent',
        'next_arrival' => 'Prochaine arrivée',
        'wifi' => 'Wi-Fi',
        'checkout' => 'Informations de départ',
        'rules' => 'Règlement',
        'tips' => 'Bonnes adresses',
        'contacts' => 'Contacts',
        'items' => 'Valeurs (liste)',
        'text' => 'Texte',
    ];

    /**
     * The data of a view, or null when the payload has nothing to show (the panel is then skipped).
     *
     * @param array<string, mixed>|null $payload
     *
     * @return array<string, mixed>|null
     */
    public static function extract(string $view, ?array $payload): ?array
    {
        if (null === $payload) {
            return null;
        }
        $title = self::str($payload['title'] ?? null);
        $guest = \is_array($payload['guest'] ?? null) ? $payload['guest'] : null;
        $data = match ($view) {
            'welcome' => null === self::str($payload['welcomeText'] ?? null) && null === $guest ? null
                : ['title' => $title, 'text' => self::str($payload['welcomeText'] ?? null), 'firstName' => self::str($guest['firstName'] ?? null)],
            'guest' => null === self::str($guest['firstName'] ?? null) ? null : [
                'firstName' => self::str($guest['firstName']), 'departure' => self::str($guest['departure'] ?? null), 'checkOut' => self::str($guest['checkOut'] ?? null), 'title' => $title,
            ],
            'next_arrival' => \is_array($payload['nextArrival'] ?? null) && null !== self::str($payload['nextArrival']['date'] ?? null)
                ? ['date' => self::str($payload['nextArrival']['date']), 'at' => self::str($payload['nextArrival']['at'] ?? null), 'title' => $title] : null,
            'wifi' => \is_array($payload['wifi'] ?? null) && null !== self::str($payload['wifi']['ssid'] ?? null)
                ? ['ssid' => self::str($payload['wifi']['ssid']), 'password' => self::str($payload['wifi']['password'] ?? null)] : null,
            'checkout' => null === self::str($payload['checkoutInfo'] ?? null) ? null
                : ['text' => self::str($payload['checkoutInfo']), 'checkOut' => self::str($guest['checkOut'] ?? null)],
            'rules' => self::textView($payload['houseRules'] ?? null, $title),
            'tips' => self::textView($payload['localTips'] ?? null, $title),
            'contacts' => self::textView($payload['contacts'] ?? null, $title),
            'text' => self::textView($payload['text'] ?? null, $title),
            'items' => \is_array($payload['items'] ?? null) && [] !== $payload['items'] ? ['title' => $title, 'items' => array_values($payload['items'])] : null,
            default => null,
        };

        return $data;
    }

    /** @return array{title: ?string, text: string}|null */
    private static function textView(mixed $text, ?string $title): ?array
    {
        $text = self::str($text);

        return null === $text ? null : ['title' => $title, 'text' => $text];
    }

    public static function str(mixed $value): ?string
    {
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
