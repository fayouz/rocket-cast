<?php

namespace App\Secret;

use Rocket\Core\Secrets\SecretsException;
use Rocket\Core\Secrets\SecretVault;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Credentials kept in the rocket-core secrets vault (Administration → Secrets, key ROCKET_SECRETS_KEY): each seal()
 * stores the JSON of the credentials as the secret "cast.credentials.<random>" and returns "vault:<name>".
 * Transition: a value sealed by the former libsodium store ("v1:…") is still opened by App\Secret\SodiumSecretStore;
 * `php bin/console app:secrets:migrate` moves them into the vault and deletes the orphan secrets.
 */
#[AsAlias(SecretStoreInterface::class)]
final class VaultSecretStore implements SecretStoreInterface
{
    public const PREFIX = 'vault:';
    public const NAME_PREFIX = 'cast.credentials.';

    public function __construct(private readonly SecretVault $vault, private readonly SodiumSecretStore $legacy)
    {
    }

    public function seal(#[\SensitiveParameter] array $secrets): string
    {
        if ([] === $secrets) {
            return '';
        }
        if (!$this->vault->isConfigured()) {
            throw new HttpException(503, 'Coffre des secrets indisponible (ROCKET_SECRETS_KEY) : '.$this->vault->configurationError());
        }
        $name = self::NAME_PREFIX.bin2hex(random_bytes(12));
        $this->vault->set($name, json_encode(array_map('strval', $secrets), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));

        return self::PREFIX.$name;
    }

    public function open(string $sealed): array
    {
        if ('' === $sealed) {
            return [];
        }
        if (!self::isVaultReference($sealed)) {
            return $this->legacy->open($sealed);
        }
        try {
            $data = json_decode($this->vault->get(substr($sealed, \strlen(self::PREFIX))), true);
        } catch (SecretsException) {
            $data = null;
        }
        if (!\is_array($data)) {
            throw new SecretException('Les identifiants enregistrés sont introuvables ou illisibles dans le coffre des secrets : saisissez-les à nouveau.');
        }

        return array_map('strval', $data);
    }

    public function forget(string $sealed): void
    {
        if (self::isVaultReference($sealed)) {
            $this->vault->delete(substr($sealed, \strlen(self::PREFIX)));
        }
    }

    public static function isVaultReference(string $sealed): bool
    {
        return str_starts_with($sealed, self::PREFIX.self::NAME_PREFIX);
    }
}
