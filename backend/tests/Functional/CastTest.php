<?php

namespace App\Tests\Functional;

use App\Entity\Screen;
use App\Entity\Source;
use App\Tests\ApiTestTrait;
use App\Tests\Support\HttpMock;
use Rocket\Core\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CastTest extends WebTestCase
{
    use ApiTestTrait {
        setUp as private apiSetUp;
    }
    use ClockSensitiveTrait;

    private const PMS = 'https://pms.test';
    private const TV_TOKEN = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789-_abcde';

    private string $admin;
    private string $user;

    protected function setUp(): void
    {
        $this->apiSetUp();
        HttpMock::reset();
        static::getContainer()->get('cache.app')->clear();
        // Monday 28 September 2026, 10:00 in Paris
        static::mockTime('2026-09-28 08:00:00 UTC');
        $this->admin = 'Bearer '.$this->jwtFor($this->createUser('admin@example.org', ['ROLE_ADMIN']));
        $this->user = 'Bearer '.$this->jwtFor($this->createUser('alice@example.org'));
    }

    /** @return array<string, mixed> */
    private function kiosk(string $token): array
    {
        $this->client->request('GET', '/api/public/screens/'.$token, server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_USER_AGENT' => 'SmartTV/1.0']);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private function pmsTv(array $body = []): array
    {
        return $body + [
            'property' => 'Loft du Vieux-Port',
            'latitude' => 43.29, 'longitude' => 5.37,
            'today' => '2026-09-28',
            'guest' => ['firstName' => 'Camille', 'departure' => '2026-09-30', 'checkOut' => '11:00'],
            'nextArrival' => '2026-10-02',
            'nextArrivalAt' => '2026-10-02T16:00:00+02:00',
            'reloadAt' => '2026-10-02T15:30:00+02:00',
            'lang' => 'fr', 'languages' => ['fr'], 'style' => ['accent' => '#ff0000', 'layout' => 'classic', 'coverUrl' => null, 'documentCover' => false],
            'content' => ['welcomeText' => 'Bienvenue Camille !', 'wifiSsid' => 'Loft', 'wifiPassword' => 'soleil', 'checkoutInfo' => 'Clés sur la table.', 'houseRules' => '', 'localTips' => '', 'contacts' => ''],
        ];
    }

    private function createPmsSource(): string
    {
        $source = $this->api('POST', '/api/sources', [
            'name' => 'Loft', 'type' => 'rocket_pms', 'refreshSeconds' => 300,
            'config' => ['baseUrl' => self::PMS.'/', 'lang' => 'fr'], 'secrets' => ['tvToken' => self::TV_TOKEN],
        ], $this->admin);
        $this->assertStatus(201);

        return $source['id'];
    }

    public function testScreensHaveARevocableKioskLinkAndAHeartbeat(): void
    {
        $this->api('GET', '/api/screens');
        $this->assertStatus(401);

        $screen = $this->api('POST', '/api/screens', ['name' => 'TV du hall', 'orientation' => 'portrait', 'timezone' => 'America/Montreal'], $this->user);
        $this->assertStatus(201);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $screen['token']);
        self::assertSame('http://localhost:3600/s/'.$screen['token'], $screen['kioskUrl']);
        self::assertSame(substr($screen['token'], -4), $screen['tokenHint']);
        self::assertFalse($screen['online']);
        // Only the hash is stored
        $stored = $this->em()->getConnection()->fetchOne('SELECT token_hash FROM screen');
        self::assertSame(hash('sha256', $screen['token']), $stored);
        // The list never shows the token again
        $list = $this->api('GET', '/api/screens', authorization: $this->user);
        self::assertArrayNotHasKey('token', $list[0]);

        $this->api('POST', '/api/screens', ['name' => 'X', 'timezone' => 'Mars/Olympus'], $this->user);
        $this->assertStatus(422);

        // Public payload: no playlist yet, never cached, not indexed
        $payload = $this->kiosk($screen['token']);
        $this->assertStatus(200);
        self::assertSame('no-store, private', $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $this->client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertSame(['name' => 'TV du hall', 'orientation' => 'portrait', 'timezone' => 'America/Montreal', 'locale' => 'fr-FR', 'enabled' => true], $payload['screen']);
        self::assertNull($payload['playlist']);
        self::assertSame([], $payload['panels']);
        $seen = $this->api('GET', '/api/screens/'.$screen['id'], authorization: $this->user);
        self::assertSame('2026-09-28T08:00:00+00:00', $seen['lastSeenAt']);
        self::assertTrue($seen['online']);
        self::assertSame('SmartTV/1.0', $seen['lastUserAgent']);

        // Rotation: the old link stops working
        $rotated = $this->api('POST', '/api/screens/'.$screen['id'].'/token', authorization: $this->user);
        $this->kiosk($screen['token']);
        $this->assertStatus(404);
        $this->kiosk($rotated['token']);
        $this->assertStatus(200);
        // Revocation
        $this->api('DELETE', '/api/screens/'.$screen['id'].'/token', authorization: $this->user);
        $this->kiosk($rotated['token']);
        $this->assertStatus(404);
        self::assertFalse($this->api('GET', '/api/screens/'.$screen['id'], authorization: $this->user)['hasToken']);
    }

    public function testInvalidTokensAreRateLimited(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->kiosk(str_repeat('a', 42).$i);
            $this->assertStatus(404);
        }
        $this->kiosk(str_repeat('b', 43));
        $this->assertStatus(429);
    }

    public function testPlaylistsAreValidatedAndScheduledInTheScreenTimeZone(): void
    {
        $this->api('POST', '/api/playlists', ['name' => 'Bad', 'panels' => [['type' => 'video']]], $this->user);
        $this->assertStatus(422);
        $this->api('POST', '/api/playlists', ['name' => 'Bad', 'panels' => [['type' => 'image', 'settings' => ['url' => 'http://insecure.example/a.png']]]], $this->user);
        $this->assertStatus(422);
        self::assertStringContainsString('Panneau 1 (Image)', (string) $this->client->getResponse()->getContent());
        $this->api('POST', '/api/playlists', ['name' => 'Bad', 'panels' => [['type' => 'clock', 'duration' => 2]]], $this->user);
        $this->assertStatus(422);

        $playlist = $this->api('POST', '/api/playlists', ['name' => 'Hall', 'accent' => '#FF8800', 'panels' => [
            ['type' => 'welcome', 'duration' => 10, 'settings' => ['title' => 'Bonjour', 'text' => 'Bienvenue', 'unknown' => 'dropped'], 'schedule' => ['days' => [1], 'from' => '08:00', 'until' => '12:00']],
            ['type' => 'welcome', 'settings' => ['title' => 'Bon après-midi'], 'schedule' => ['days' => [1], 'from' => '12:00', 'until' => '18:00']],
            ['type' => 'clock', 'duration' => 20],
            ['type' => 'wifi', 'enabled' => false, 'settings' => ['ssid' => 'Guest']],
            ['type' => 'image', 'settings' => ['url' => 'http://cloud.test/s/abc/photo.jpg']],
            ['type' => 'qrcode', 'settings' => ['value' => 'https://example.org']],
        ]], $this->user);
        $this->assertStatus(201);
        self::assertSame('#ff8800', $playlist['accent']);
        self::assertCount(6, $playlist['panels']);
        self::assertSame(['title' => 'Bonjour', 'text' => 'Bienvenue'], $playlist['panels'][0]['settings']);
        self::assertSame(15, $playlist['panels'][1]['duration']);
        self::assertSame(75, $playlist['duration']);

        $screen = $this->api('POST', '/api/screens', ['name' => 'Hall', 'playlistId' => $playlist['id']], $this->user);
        $payload = $this->kiosk($screen['token']);
        self::assertSame(['Bonjour', null, null, ''], array_map(static fn (array $p) => $p['settings']['title'] ?? null, $payload['panels']));
        self::assertSame(['welcome', 'clock', 'image', 'qrcode'], array_column($payload['panels'], 'type'));
        self::assertSame(['name' => 'Hall', 'accent' => '#ff8800', 'theme' => 'dark'], $payload['playlist']);
        // Asked again in 15 minutes at most, or at the next schedule change (12:00 in Paris)
        self::assertSame('2026-09-28T10:15:00+02:00', $payload['reloadAt']);
        static::mockTime('2026-09-28 09:50:00 UTC');
        self::assertSame('2026-09-28T12:00:00+02:00', $this->kiosk($screen['token'])['reloadAt']);

        // Same screen in Montréal (04:00): no welcome panel at all
        $this->api('PATCH', '/api/screens/'.$screen['id'], ['timezone' => 'America/Montreal'], $this->user);
        self::assertSame(['clock', 'image', 'qrcode'], array_column($this->kiosk($screen['token'])['panels'], 'type'));

        // Afternoon in Paris
        static::mockTime('2026-09-28 11:30:00 UTC');
        $this->api('PATCH', '/api/screens/'.$screen['id'], ['timezone' => 'Europe/Paris'], $this->user);
        self::assertSame('Bon après-midi', $this->kiosk($screen['token'])['panels'][0]['settings']['title']);

        // Live preview: every enabled panel, flagged
        $preview = $this->api('POST', '/api/playlists/preview', ['panels' => $playlist['panels'], 'timezone' => 'Europe/Paris'], $this->user);
        $this->assertStatus(200);
        self::assertSame([false, true, true, true, true], array_column($preview['panels'], 'activeNow'));

        // Disabled screen: nothing shown
        $this->api('PATCH', '/api/screens/'.$screen['id'], ['enabled' => false], $this->user);
        $disabled = $this->kiosk($screen['token']);
        self::assertFalse($disabled['screen']['enabled']);
        self::assertSame([], $disabled['panels']);

        // Deleting the playlist detaches the screens
        $this->api('DELETE', '/api/playlists/'.$playlist['id'], authorization: $this->user);
        $this->assertStatus(204);
        self::assertNull($this->api('GET', '/api/screens/'.$screen['id'], authorization: $this->user)['playlist']);
    }

    public function testSourcesAreSealedAndOnlyManagedByAdministrators(): void
    {
        $types = $this->api('GET', '/api/source-types', authorization: $this->user);
        $ids = array_column($types, 'id');
        sort($ids);
        self::assertSame(['rocket_place', 'rocket_pms', 'web_json'], $ids);

        $this->api('POST', '/api/sources', ['name' => 'Loft', 'type' => 'rocket_pms', 'config' => ['baseUrl' => self::PMS], 'secrets' => ['tvToken' => self::TV_TOKEN]], $this->user);
        $this->assertStatus(403);
        $this->api('POST', '/api/sources', ['name' => 'Loft', 'type' => 'rocket_pms', 'config' => ['baseUrl' => self::PMS]], $this->admin);
        $this->assertStatus(422);
        $this->api('POST', '/api/sources', ['name' => 'Loft', 'type' => 'rocket_pms', 'config' => ['baseUrl' => 'ftp://x'], 'secrets' => ['tvToken' => 'x']], $this->admin);
        $this->assertStatus(422);

        $id = $this->createPmsSource();
        $raw = (string) $this->em()->getConnection()->fetchOne('SELECT sealed_secrets FROM source');
        self::assertStringStartsWith('v1:', $raw);
        self::assertStringNotContainsString(self::TV_TOKEN, $raw);

        $admin = $this->api('GET', '/api/sources/'.$id, authorization: $this->admin);
        self::assertSame(['tvToken' => true], $admin['secrets']);
        self::assertSame(['baseUrl' => self::PMS, 'lang' => 'fr'], $admin['config']);
        self::assertStringNotContainsString(self::TV_TOKEN, (string) $this->client->getResponse()->getContent());
        $user = $this->api('GET', '/api/sources', authorization: $this->user)[0];
        self::assertArrayNotHasKey('config', $user);
        self::assertContains('guest', array_column($user['capabilities'], 'id'));

        // Renaming keeps the token; "" removes it (required: refused)
        $this->api('PATCH', '/api/sources/'.$id, ['name' => 'Loft 2', 'secrets' => ['tvToken' => null]], $this->admin);
        $this->assertStatus(200);
        $this->api('PATCH', '/api/sources/'.$id, ['secrets' => ['tvToken' => '']], $this->admin);
        $this->assertStatus(422);

        // Test: fetched now
        HttpMock::json(self::PMS.'/api/public/tv/'.self::TV_TOKEN, $this->pmsTv());
        $test = $this->api('POST', '/api/sources/'.$id.'/test', authorization: $this->admin);
        self::assertTrue($test['ok']);
        self::assertSame('Camille', $test['payload']['guest']['firstName']);
        self::assertSame(['ssid' => 'Loft', 'password' => 'soleil'], $test['payload']['wifi']);
        self::assertSame(self::PMS.'/api/public/tv/'.self::TV_TOKEN.'?lang=fr', HttpMock::$requests[0]['url']);

        HttpMock::json(self::PMS.'/api/public/tv/'.self::TV_TOKEN, ['detail' => 'Lien invalide.'], 404);
        $test = $this->api('POST', '/api/sources/'.$id.'/test', authorization: $this->admin);
        self::assertFalse($test['ok']);
        self::assertStringContainsString('HTTP 404', $test['error']);
    }

    public function testRocketPmsSourcePanelsFollowTheGuestAndSurviveOutages(): void
    {
        $id = $this->createPmsSource();
        HttpMock::json(self::PMS.'/api/public/tv/', $this->pmsTv());
        $playlist = $this->api('POST', '/api/playlists', ['name' => 'Loft', 'panels' => [
            ['type' => 'source', 'settings' => ['sourceId' => $id, 'view' => 'welcome']],
            ['type' => 'source', 'settings' => ['sourceId' => $id, 'view' => 'guest', 'title' => 'Votre séjour']],
            ['type' => 'source', 'settings' => ['sourceId' => $id, 'view' => 'next_arrival']],
            ['type' => 'source', 'settings' => ['sourceId' => $id, 'view' => 'wifi']],
            ['type' => 'source', 'settings' => ['sourceId' => $id, 'view' => 'checkout']],
            ['type' => 'source', 'settings' => ['sourceId' => $id, 'view' => 'rules']],
        ]], $this->user);
        $this->assertStatus(201);
        $this->api('POST', '/api/playlists', ['name' => 'X', 'panels' => [['type' => 'source', 'settings' => ['sourceId' => $id, 'view' => 'items']]]], $this->user);
        $this->assertStatus(422);

        $screen = $this->api('POST', '/api/screens', ['name' => 'TV', 'playlistId' => $playlist['id']], $this->user);
        $payload = $this->kiosk($screen['token']);
        // No house rules: that panel is skipped
        self::assertSame(['welcome', 'guest', 'next_arrival', 'wifi', 'checkout'], array_column(array_column($payload['panels'], 'settings'), 'view'));
        self::assertSame(['title' => 'Loft du Vieux-Port', 'text' => 'Bienvenue Camille !', 'firstName' => 'Camille'], $payload['panels'][0]['data']);
        self::assertSame('Votre séjour', $payload['panels'][1]['settings']['title']);
        self::assertSame(['date' => '2026-10-02', 'at' => '2026-10-02T16:00:00+02:00', 'title' => 'Loft du Vieux-Port'], $payload['panels'][2]['data']);
        self::assertArrayNotHasKey('sourceId', $payload['panels'][0]['settings']);
        self::assertCount(1, HttpMock::$requests);

        // Cached for refreshSeconds
        $this->kiosk($screen['token']);
        self::assertCount(1, HttpMock::$requests);

        // Rocket PMS down: the screen keeps the last payload, the source shows the error, retried after a minute
        static::mockTime('2026-09-28 08:06:00 UTC');
        HttpMock::on(self::PMS.'/api/public/tv/', static fn () => new MockResponse('', ['error' => 'Connection refused']));
        self::assertSame('Camille', $this->kiosk($screen['token'])['panels'][1]['data']['firstName']);
        self::assertCount(2, HttpMock::$requests);
        $this->kiosk($screen['token']);
        self::assertCount(2, HttpMock::$requests);
        $source = $this->api('GET', '/api/sources/'.$id, authorization: $this->user);
        self::assertStringContainsString('Source injoignable', $source['lastError']);

        // The guest left: no guest panel, the welcome panel keeps the text
        static::mockTime('2026-09-28 08:08:00 UTC');
        HttpMock::json(self::PMS.'/api/public/tv/', $this->pmsTv(['guest' => null]));
        $payload = $this->kiosk($screen['token']);
        self::assertSame(['welcome', 'next_arrival', 'wifi', 'checkout'], array_column(array_column($payload['panels'], 'settings'), 'view'));
        self::assertNull($payload['panels'][0]['data']['firstName']);
        self::assertNull($this->api('GET', '/api/sources/'.$id, authorization: $this->user)['lastError']);
    }

    public function testPlaceAndWebJsonSources(): void
    {
        $place = $this->api('POST', '/api/sources', [
            'name' => 'Salon', 'type' => 'rocket_place',
            'config' => ['baseUrl' => 'https://place.test', 'placeId' => '01990000-0000-7000-8000-000000000001', 'impersonate' => 'alice@example.org', 'filter' => 'Température, Fenêtre'],
            'secrets' => ['token' => 'rpl_secret'],
        ], $this->admin);
        $this->assertStatus(201);
        HttpMock::json('https://place.test/api/places/01990000-0000-7000-8000-000000000001/domotique', ['sections' => [
            ['name' => 'Homey', 'cards' => [['title' => 'Salon', 'items' => [
                ['label' => 'Température', 'value' => 21.5, 'unit' => '°C'], ['label' => 'Humidité', 'value' => 40], ['label' => 'Fenêtre', 'value' => false],
            ]]], 'error' => null],
        ]]);
        $json = $this->api('POST', '/api/sources', [
            'name' => 'Bureau', 'type' => 'web_json',
            'config' => ['url' => 'https://api.test/status', 'title' => 'Bureau', 'fields' => "Visiteurs | office.visitors\nSalles | office.rooms.0.name | ", 'textPath' => 'message'],
            'secrets' => ['authorization' => 'Bearer abc'],
        ], $this->admin);
        $this->assertStatus(201);
        $this->api('POST', '/api/sources', ['name' => 'Bad', 'type' => 'web_json', 'config' => ['url' => 'https://api.test', 'fields' => 'no path']], $this->admin);
        $this->assertStatus(422);
        HttpMock::json('https://api.test/status', ['office' => ['visitors' => 42, 'rooms' => [['name' => 'Jupiter']]], 'message' => 'Réunion à 14 h']);

        $playlist = $this->api('POST', '/api/playlists', ['name' => 'Bureau', 'panels' => [
            ['type' => 'source', 'settings' => ['sourceId' => $place['id'], 'view' => 'items']],
            ['type' => 'source', 'settings' => ['sourceId' => $json['id'], 'view' => 'items']],
            ['type' => 'source', 'settings' => ['sourceId' => $json['id'], 'view' => 'text']],
        ]], $this->user);
        $screen = $this->api('POST', '/api/screens', ['name' => 'Bureau', 'playlistId' => $playlist['id']], $this->user);
        $panels = $this->kiosk($screen['token'])['panels'];
        self::assertSame([['label' => 'Température', 'value' => '21.5', 'unit' => '°C'], ['label' => 'Fenêtre', 'value' => 'Non']], $panels[0]['data']['items']);
        self::assertSame([['label' => 'Visiteurs', 'value' => '42'], ['label' => 'Salles', 'value' => 'Jupiter']], $panels[1]['data']['items']);
        self::assertSame(['title' => 'Bureau', 'text' => 'Réunion à 14 h'], $panels[2]['data']);

        $headers = array_column(HttpMock::$requests, 'headers', 'url');
        self::assertContains('Authorization: Bearer rpl_secret', $headers['https://place.test/api/places/01990000-0000-7000-8000-000000000001/domotique']);
        self::assertContains('X-Impersonate-User: alice@example.org', $headers['https://place.test/api/places/01990000-0000-7000-8000-000000000001/domotique']);
        self::assertContains('Authorization: Bearer abc', $headers['https://api.test/status']);
    }

    public function testPairingAFreshScreen(): void
    {
        $this->client->request('POST', '/api/public/pairings', server: ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatus(201);
        $pairing = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertMatchesRegularExpression('/^[2-9A-Z]{3}-[2-9A-Z]{3}$/', $pairing['code']);

        $poll = fn () => $this->api('POST', '/api/public/pairings/poll', ['secret' => $pairing['secret']]);
        self::assertSame('pending', $poll()['status']);
        $this->api('POST', '/api/public/pairings/poll', ['secret' => 'guess']);
        $this->assertStatus(404);

        $this->api('POST', '/api/screens/pair', ['code' => 'ZZZ-ZZZ'], $this->user);
        $this->assertStatus(422);
        $screen = $this->api('POST', '/api/screens/pair', ['code' => strtolower(str_replace('-', ' ', $pairing['code'])), 'name' => 'TV de la cuisine'], $this->user);
        $this->assertStatus(201);
        self::assertSame('TV de la cuisine', $screen['name']);
        // The code is used
        $this->api('POST', '/api/screens/pair', ['code' => $pairing['code']], $this->user);
        $this->assertStatus(422);

        $paired = $poll();
        self::assertSame('paired', $paired['status']);
        self::assertSame('TV de la cuisine', $paired['screen']);
        $this->kiosk($paired['token']);
        $this->assertStatus(200);
        // Delivered once
        $poll();
        $this->assertStatus(404);

        // Re-pairing an existing screen rotates its link
        $this->client->request('POST', '/api/public/pairings', server: ['HTTP_ACCEPT' => 'application/json']);
        $again = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->api('POST', '/api/screens/pair', ['code' => $again['code'], 'screenId' => $screen['id']], $this->user);
        $this->assertStatus(200);
        $this->kiosk($paired['token']);
        $this->assertStatus(404);
        $new = $this->api('POST', '/api/public/pairings/poll', ['secret' => $again['secret']]);
        $this->kiosk($new['token']);
        $this->assertStatus(200);

        // Expired codes cannot be paired
        $this->client->request('POST', '/api/public/pairings', server: ['HTTP_ACCEPT' => 'application/json']);
        $late = json_decode((string) $this->client->getResponse()->getContent(), true);
        static::mockTime('2026-09-28 08:16:00 UTC');
        $this->api('POST', '/api/screens/pair', ['code' => $late['code']], $this->user);
        $this->assertStatus(422);
        self::assertSame(1, $this->em()->getRepository(Screen::class)->count([]));
    }

    public function testDemoSeederAndDemoFeeds(): void
    {
        $users = ['alice@example.org' => $this->em()->getRepository(User::class)->findOneBy(['email' => 'alice@example.org'])];
        $seeder = static::getContainer()->get(\App\Command\CastDemoSeeder::class);
        $io = new \Symfony\Component\Console\Style\SymfonyStyle(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\NullOutput());
        $seeder->seed($users, $io);
        $seeder->seed($users, $io);
        self::assertSame(3, $this->em()->getRepository(Source::class)->count([]));
        self::assertSame(2, $this->em()->getRepository(Screen::class)->count([]));

        // The demo feeds answer like the real services
        $tv = $this->api('GET', '/demo/pms/api/public/tv/'.\App\Controller\DemoFeedController::TV_TOKEN);
        $this->assertStatus(200);
        self::assertSame('Camille', \App\Source\Type\RocketPmsSource::normalise($tv)['guest']['firstName']);
        $place = $this->api('GET', '/demo/place/api/places/x/domotique');
        self::assertCount(4, \App\Source\Type\RocketPlaceSource::normalise($place)['items']);
        $this->api('GET', '/demo/json');
        $this->assertStatus(200);
    }
}
