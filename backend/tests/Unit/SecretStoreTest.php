<?php

namespace App\Tests\Unit;

use App\Secret\SecretException;
use App\Secret\SodiumSecretStore;
use PHPUnit\Framework\TestCase;

/** Former libsodium store: only reads the values sealed before the rocket-core vault. */
final class SecretStoreTest extends TestCase
{
    public function testOpensTheFormerFormatWithAnyCandidateKey(): void
    {
        $sealed = SodiumSecretStore::sealWith(str_repeat('ab', 32), ['token' => 'rpl_very_secret']);
        self::assertStringStartsWith('v1:', $sealed);
        self::assertStringNotContainsString('rpl_very_secret', $sealed);
        self::assertSame(['token' => 'rpl_very_secret'], (new SodiumSecretStore(str_repeat('ab', 32), 'new-vault-key', 'app-secret'))->open($sealed));
        self::assertSame(['token' => 'rpl_very_secret'], (new SodiumSecretStore(null, str_repeat('ab', 32), 'app-secret'))->open($sealed));
        self::assertSame([], (new SodiumSecretStore(null, '', 'x'))->open(''));
    }

    public function testAnotherKeyCannotOpen(): void
    {
        $sealed = SodiumSecretStore::sealWith(str_repeat('ab', 32), ['token' => 'secret']);
        $this->expectException(SecretException::class);
        (new SodiumSecretStore(null, str_repeat('cd', 32), 'x'))->open($sealed);
    }

    public function testFallsBackOnAppSecret(): void
    {
        $sealed = SodiumSecretStore::sealWith('app-secret', ['a' => 'b']);
        self::assertSame(['a' => 'b'], (new SodiumSecretStore(null, '', 'app-secret'))->open($sealed));
    }
}
