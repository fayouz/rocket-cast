<?php

namespace App\Secret;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * libsodium secretbox keyed by ROCKET_SECRETS_KEY (64 hex characters, or any string: hashed), or APP_SECRET when it is
 * empty (development only). Format: "v1:" + base64(nonce + ciphertext) of the JSON of the secrets.
 */
#[AsAlias(SecretStoreInterface::class)]
final class SodiumSecretStore implements SecretStoreInterface
{
    private const PREFIX = 'v1:';

    private readonly string $key;

    public function __construct(
        #[Autowire(env: 'ROCKET_SECRETS_KEY')] #[\SensitiveParameter] string $masterKey,
        #[Autowire(env: 'APP_SECRET')] #[\SensitiveParameter] string $appSecret,
    ) {
        $material = '' !== $masterKey ? $masterKey : $appSecret;
        $this->key = sodium_crypto_generichash('rocket-cast/secrets|'.$material, '', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function seal(#[\SensitiveParameter] array $secrets): string
    {
        if ([] === $secrets) {
            return '';
        }
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX.base64_encode($nonce.sodium_crypto_secretbox(json_encode($secrets, \JSON_THROW_ON_ERROR), $nonce, $this->key));
    }

    public function open(string $sealed): array
    {
        if ('' === $sealed) {
            return [];
        }
        $raw = str_starts_with($sealed, self::PREFIX) ? base64_decode(substr($sealed, \strlen(self::PREFIX)), true) : false;
        $plain = false === $raw || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ? false : sodium_crypto_secretbox_open(
            substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );
        $data = false === $plain ? null : json_decode($plain, true);
        if (!\is_array($data)) {
            throw new SecretException('Les identifiants enregistrés ne peuvent pas être déchiffrés (ROCKET_SECRETS_KEY a changé) : saisissez-les à nouveau.');
        }

        return array_map('strval', $data);
    }
}
