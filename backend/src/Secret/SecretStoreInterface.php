<?php

namespace App\Secret;

/**
 * Encryption of the credentials stored in the database (source tokens, API keys). Kept behind this interface so the
 * implementation can later be swapped for the rocket-core vault without touching the sources.
 */
interface SecretStoreInterface
{
    /** @param array<string, string> $secrets */
    public function seal(#[\SensitiveParameter] array $secrets): string;

    /**
     * @return array<string, string>
     *
     * @throws SecretException when the value cannot be decrypted (master key changed)
     */
    public function open(string $sealed): array;
}
