<?php

namespace App\Command;

use App\Cast\Panels;
use App\Controller\DemoFeedController;
use App\Entity\Playlist;
use App\Entity\Screen;
use App\Entity\Source;
use App\Repository\PlaylistRepository;
use App\Secret\SecretStoreInterface;
use Doctrine\ORM\EntityManagerInterface;
use Rocket\Core\Command\DemoSeederInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Demo: three sources served by the API itself (DemoFeedController: Rocket PMS, Rocket Place, Web JSON), a playlist
 * using every panel type, a demo screen (/s/DEMO_SCREEN_TOKEN) and a second screen without link.
 */
final class CastDemoSeeder implements DemoSeederInterface
{
    public const PLAYLIST = 'Accueil voyageurs (démo)';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlaylistRepository $playlists,
        private readonly SecretStoreInterface $secrets,
        private readonly Panels $panels,
        #[Autowire(env: 'default::DEMO_SOURCE_BASE_URL')] private readonly ?string $sourceBaseUrl = null,
        #[Autowire(env: 'default::DEMO_SCREEN_TOKEN')] private readonly ?string $screenToken = null,
    ) {
    }

    public function seed(array $users, SymfonyStyle $io): void
    {
        if (null !== $this->playlists->findOneBy(['name' => self::PLAYLIST])) {
            return;
        }
        $base = rtrim($this->sourceBaseUrl ?: 'http://localhost:8600', '/').'/api/public/demo';
        $pms = $this->source('Loft du Vieux-Port (Rocket PMS)', 'rocket_pms', ['baseUrl' => $base.'/pms', 'lang' => 'fr'], ['tvToken' => DemoFeedController::TV_TOKEN], 300);
        $place = $this->source('Capteurs du salon (Rocket Place)', 'rocket_place', [
            'baseUrl' => $base.'/place', 'placeId' => '01990000-0000-7000-8000-000000000001', 'impersonate' => '', 'filter' => '',
        ], ['token' => 'rpl_demo_rocket_place_do_not_use_in_production'], 60);
        $json = $this->source('Infos bureau (Web JSON)', 'web_json', [
            'url' => $base.'/json', 'title' => 'Aujourd’hui au bureau',
            'fields' => "Visiteurs | office.visitors\nSalles libres | office.meetingRooms.free", 'textPath' => 'message.body',
        ], [], 120);
        $this->em->flush();

        $allWeek = ['days' => [1, 2, 3, 4, 5, 6, 7], 'from' => '07:00', 'until' => '23:00'];
        $playlist = (new Playlist())
            ->setName(self::PLAYLIST)
            ->setDescription('Tous les types de panneaux, alimentés par des sources de démonstration.')
            ->setAccent('#0ea5e9')
            ->setPanels($this->panels->normalise([
                ['type' => 'source', 'duration' => 12, 'settings' => ['sourceId' => $pms->getId()->toRfc4122(), 'view' => 'welcome']],
                ['type' => 'clock', 'duration' => 8, 'settings' => ['label' => 'Marseille', 'showDate' => true]],
                ['type' => 'weather', 'duration' => 12, 'settings' => ['label' => 'Météo à Marseille', 'latitude' => 43.2965, 'longitude' => 5.3698, 'days' => 3]],
                ['type' => 'source', 'duration' => 12, 'settings' => ['sourceId' => $pms->getId()->toRfc4122(), 'view' => 'wifi']],
                ['type' => 'source', 'duration' => 10, 'settings' => ['sourceId' => $pms->getId()->toRfc4122(), 'view' => 'next_arrival', 'title' => 'Prochaine arrivée']],
                ['type' => 'source', 'duration' => 12, 'settings' => ['sourceId' => $pms->getId()->toRfc4122(), 'view' => 'checkout']],
                ['type' => 'source', 'duration' => 10, 'settings' => ['sourceId' => $place->getId()->toRfc4122(), 'view' => 'items', 'title' => 'Dans le loft']],
                ['type' => 'source', 'duration' => 10, 'settings' => ['sourceId' => $json->getId()->toRfc4122(), 'view' => 'items']],
                ['type' => 'source', 'duration' => 10, 'settings' => ['sourceId' => $json->getId()->toRfc4122(), 'view' => 'text']],
                ['type' => 'welcome', 'duration' => 10, 'schedule' => ['days' => [6, 7], 'from' => '08:00', 'until' => '12:00'], 'settings' => ['title' => 'Bon week-end !', 'text' => 'Le marché du Vieux-Port est ouvert jusqu’à 13 h.']],
                ['type' => 'wifi', 'duration' => 10, 'enabled' => false, 'settings' => ['ssid' => 'Invites', 'password' => 'bienvenue', 'security' => 'WPA', 'showQr' => true]],
                ['type' => 'checkout', 'duration' => 10, 'enabled' => false, 'settings' => ['time' => '11:00', 'text' => 'Merci de laisser les clés sur la table.']],
                ['type' => 'richtext', 'duration' => 12, 'schedule' => $allWeek, 'settings' => ['title' => 'Bon à savoir', 'text' => "**Tri sélectif** : poubelle jaune sous l’évier.\n\n- Serviettes de plage dans l’entrée\n- Parapluies derrière la porte"]],
                ['type' => 'qrcode', 'duration' => 10, 'settings' => ['value' => 'https://github.com/fayouz/rocket-cast', 'title' => 'Laissez-nous un avis', 'caption' => 'Scannez avec votre téléphone']],
                ['type' => 'image', 'duration' => 10, 'settings' => ['url' => 'https://images.unsplash.com/photo-1566438480900-0609be27a4be?w=1600', 'fit' => 'cover', 'caption' => 'Le Vieux-Port au coucher du soleil']],
            ]));
        $this->em->persist($playlist);

        $hall = (new Screen())->setName('TV du salon (démo)')->setLocation('Loft du Vieux-Port')->setPlaylist($playlist)->setTimezone('Europe/Paris');
        if (null !== $this->screenToken && preg_match('/^[A-Za-z0-9_-]{43}$/', $this->screenToken)) {
            $hall->useToken($this->screenToken);
        } else {
            $hall->rotateToken();
        }
        $this->em->persist($hall);
        $this->em->persist((new Screen())->setName('Tablette de l’entrée')->setLocation('Entrée')->setOrientation('portrait')->setPlaylist($playlist));
        $this->em->flush();

        $io->text('Rocket Cast : 3 sources, 1 playlist, 2 écrans'.(null !== $this->screenToken ? ' (écran de démo : /s/'.$this->screenToken.')' : '').'.');
    }

    /**
     * @param array<string, mixed>  $config
     * @param array<string, string> $secrets
     */
    private function source(string $name, string $type, array $config, array $secrets, int $refresh): Source
    {
        $source = (new Source())->setName($name)->setType($type)->setConfig($config)->setSealedSecrets($this->secrets->seal($secrets))->setRefreshSeconds($refresh);
        $this->em->persist($source);

        return $source;
    }
}
