<?php

namespace App\Secret;

/**
 * Storage of the credentials of the sources (tokens, API keys) and of the kiosk tokens waiting for a pairing. The
 * implementation is App\Secret\VaultSecretStore (rocket-core secrets vault); the entities only keep the reference
 * returned by seal().
 */
interface SecretStoreInterface
{
    /**
     * @param array<string, string> $secrets
     *
     * @return string reference to keep ('' when there is nothing to keep)
     */
    public function seal(#[\SensitiveParameter] array $secrets): string;

    /**
     * @return array<string, string>
     *
     * @throws SecretException when the value cannot be read (vault key changed, secret deleted)
     */
    public function open(string $sealed): array;

    /** Deletes what seal() stored (the reference is no longer used). */
    public function forget(string $sealed): void;
}
