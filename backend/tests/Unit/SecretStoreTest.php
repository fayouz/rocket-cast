<?php

namespace App\Tests\Unit;

use App\Secret\SecretException;
use App\Secret\SodiumSecretStore;
use PHPUnit\Framework\TestCase;

final class SecretStoreTest extends TestCase
{
    public function testSealsAndOpens(): void
    {
        $store = new SodiumSecretStore(str_repeat('ab', 32), 'app-secret');
        $sealed = $store->seal(['token' => 'rpl_very_secret']);
        self::assertStringStartsWith('v1:', $sealed);
        self::assertStringNotContainsString('rpl_very_secret', $sealed);
        self::assertNotSame($sealed, $store->seal(['token' => 'rpl_very_secret']), 'Random nonce');
        self::assertSame(['token' => 'rpl_very_secret'], $store->open($sealed));
        self::assertSame('', $store->seal([]));
        self::assertSame([], $store->open(''));
    }

    public function testAnotherKeyCannotOpen(): void
    {
        $sealed = (new SodiumSecretStore(str_repeat('ab', 32), 'x'))->seal(['token' => 'secret']);
        $this->expectException(SecretException::class);
        (new SodiumSecretStore(str_repeat('cd', 32), 'x'))->open($sealed);
    }

    public function testFallsBackOnAppSecret(): void
    {
        $sealed = (new SodiumSecretStore('', 'app-secret'))->seal(['a' => 'b']);
        self::assertSame(['a' => 'b'], (new SodiumSecretStore('', 'app-secret'))->open($sealed));
    }
}
