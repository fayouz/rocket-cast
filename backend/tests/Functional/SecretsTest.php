<?php

namespace App\Tests\Functional;

use App\Entity\Source;
use App\Secret\SecretException;
use App\Secret\SecretStoreInterface;
use App\Secret\SodiumSecretStore;
use App\Secret\VaultSecretStore;
use App\Tests\ApiTestTrait;
use Rocket\Core\Secrets\SecretVault;
use Symfony\Bundle\FrameworkBundle\Console\Application as ConsoleApplication;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** Credentials of the sources in the rocket-core vault, former libsodium values migrated, never exposed by the API. */
final class SecretsTest extends WebTestCase
{
    use ApiTestTrait;

    private function store(): SecretStoreInterface
    {
        return static::getContainer()->get(SecretStoreInterface::class);
    }

    public function testCredentialsAreKeptInTheVault(): void
    {
        $store = $this->store();
        self::assertInstanceOf(VaultSecretStore::class, $store);
        $sealed = $store->seal(['token' => 'rpl_very_secret_123456']);
        self::assertStringStartsWith('vault:cast.credentials.', $sealed);
        self::assertStringNotContainsString('rpl_very', $sealed);
        $name = substr($sealed, \strlen('vault:'));
        self::assertTrue(static::getContainer()->get(SecretVault::class)->has($name));
        self::assertStringNotContainsString('rpl_very', (string) $this->em()->getConnection()->fetchOne('SELECT ciphertext FROM secret WHERE name = ?', [$name]));
        self::assertSame(['token' => 'rpl_very_secret_123456'], $store->open($sealed));
        self::assertSame('', $store->seal([]));

        $store->forget($sealed);
        self::assertFalse(static::getContainer()->get(SecretVault::class)->has($name));
        $this->expectException(SecretException::class);
        $store->open($sealed);
    }

    public function testFormerValuesAreStillReadThenMigrated(): void
    {
        $former = SodiumSecretStore::sealWith($_SERVER['APP_SECRET'] ?? $_ENV['APP_SECRET'], ['token' => 'legacy-token-abcdef']);
        self::assertSame(['token' => 'legacy-token-abcdef'], $this->store()->open($former));

        $source = (new Source())->setName('Ancienne source')->setType('web_json')->setConfig(['url' => 'https://example.org/feed.json'])->setSealedSecrets($former);
        $this->em()->persist($source);
        $this->em()->flush();
        $orphan = $this->store()->seal(['token' => 'orphan-token-000000']);

        $tester = new CommandTester((new ConsoleApplication(static::$kernel))->find('app:secrets:migrate'));
        $tester->execute(['--dry-run' => true]);
        $tester->assertCommandIsSuccessful();
        $this->em()->refresh($source);
        self::assertSame($former, $source->getSealedSecrets());

        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        $this->em()->refresh($source);
        self::assertTrue(VaultSecretStore::isVaultReference($source->getSealedSecrets()));
        self::assertSame(['token' => 'legacy-token-abcdef'], $this->store()->open($source->getSealedSecrets()));
        self::assertFalse(static::getContainer()->get(SecretVault::class)->has(substr($orphan, \strlen('vault:'))), 'Orphan deleted');

        // Idempotent.
        $reference = $source->getSealedSecrets();
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        $this->em()->refresh($source);
        self::assertSame($reference, $source->getSealedSecrets());
    }

    public function testTheApiNeverExposesTheValues(): void
    {
        $admin = 'Bearer '.$this->jwtFor($this->createUser('admin-secrets@example.org', ['ROLE_ADMIN']));
        $this->store()->seal(['token' => 'very-secret-value-9876']);

        $list = $this->api('GET', '/api/secrets', null, $admin);
        $this->assertStatus(200);
        self::assertStringNotContainsString('very-secret-value', (string) $this->client->getResponse()->getContent());
        self::assertNotEmpty(array_filter(array_column($list['secrets'], 'name'), static fn (string $n) => str_starts_with($n, 'cast.credentials.')));

        $this->api('GET', '/api/secrets', null, 'Bearer '.$this->jwtFor($this->createUser('user-secrets@example.org')));
        $this->assertStatus(403);
    }
}
