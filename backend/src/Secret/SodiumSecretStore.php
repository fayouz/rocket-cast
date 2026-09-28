<?php

namespace App\Secret;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Former store (libsodium secretbox, "v1:" + base64(nonce + ciphertext) of the JSON), kept to read the values sealed
 * before the rocket-core vault until `php bin/console app:secrets:migrate` moves them (see App\Secret\VaultSecretStore).
 * Its key was derived from ROCKET_SECRETS_KEY (any string), or APP_SECRET when empty: once ROCKET_SECRETS_KEY holds
 * the new vault key, put the former value in CAST_LEGACY_SECRETS_KEY. Every candidate is tried.
 */
final class SodiumSecretStore
{
    public const PREFIX = 'v1:';

    /** @var list<string> */
    private readonly array $keys;

    public function __construct(
        #[Autowire(env: 'default::CAST_LEGACY_SECRETS_KEY')] #[\SensitiveParameter] ?string $legacyKey,
        #[Autowire(env: 'ROCKET_SECRETS_KEY')] #[\SensitiveParameter] string $masterKey,
        #[Autowire(env: 'APP_SECRET')] #[\SensitiveParameter] string $appSecret,
    ) {
        $keys = [];
        foreach ([(string) $legacyKey, $masterKey, $appSecret] as $material) {
            if ('' !== $material) {
                $keys[] = self::derive($material);
            }
        }
        $this->keys = array_values(array_unique($keys));
    }

    private static function derive(string $material): string
    {
        return sodium_crypto_generichash('rocket-cast/secrets|'.$material, '', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    /**
     * Only for the tests (values in the former format).
     *
     * @param array<string, string> $secrets
     */
    public static function sealWith(string $material, #[\SensitiveParameter] array $secrets): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX.base64_encode($nonce.sodium_crypto_secretbox(json_encode($secrets, \JSON_THROW_ON_ERROR), $nonce, self::derive($material)));
    }

    /** @return array<string, string> */
    public function open(string $sealed): array
    {
        if ('' === $sealed) {
            return [];
        }
        $raw = str_starts_with($sealed, self::PREFIX) ? base64_decode(substr($sealed, \strlen(self::PREFIX)), true) : false;
        if (false !== $raw && \strlen($raw) > \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            foreach ($this->keys as $key) {
                $plain = sodium_crypto_secretbox_open(substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
                $data = false === $plain ? null : json_decode($plain, true);
                if (\is_array($data)) {
                    return array_map('strval', $data);
                }
            }
        }
        throw new SecretException('Les identifiants enregistrés ne peuvent pas être déchiffrés (ancienne clé : CAST_LEGACY_SECRETS_KEY) : saisissez-les à nouveau.');
    }
}
