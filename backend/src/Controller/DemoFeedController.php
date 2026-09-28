<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Fake upstream services of the demo sources (DEMO_MODE=1 only): the Rocket PMS TV endpoint, the Rocket Place
 * "domotique" endpoint and a JSON API, so the demo screen works without the other bricks nor Internet access.
 */
final class DemoFeedController extends AbstractController
{
    public const TV_TOKEN = 'demo-pms-tv-token-0000000000000000000000000';

    public function __construct(
        #[Autowire(env: 'bool:default::DEMO_MODE')] private readonly bool $demo,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/api/public/demo/pms/api/public/tv/{token}', name: 'api_demo_pms_tv', methods: ['GET'])]
    public function pms(string $token): JsonResponse
    {
        $this->guard();
        if (self::TV_TOKEN !== $token) {
            throw new NotFoundHttpException('Lien invalide.');
        }
        $today = $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Paris'));
        $next = $today->modify('+3 days')->setTime(16, 0);

        return $this->json([
            'property' => 'Loft du Vieux-Port',
            'latitude' => 43.2951, 'longitude' => 5.3740,
            'today' => $today->format('Y-m-d'),
            'guest' => ['firstName' => 'Camille', 'departure' => $today->modify('+2 days')->format('Y-m-d'), 'checkOut' => '11:00'],
            'nextArrival' => $next->format('Y-m-d'),
            'nextArrivalAt' => $next->format(\DATE_ATOM),
            'reloadAt' => $next->modify('-30 minutes')->format(\DATE_ATOM),
            'lang' => 'fr', 'languages' => ['fr', 'en'],
            'style' => ['accent' => '#0ea5e9', 'layout' => 'classic', 'coverUrl' => null, 'documentCover' => false],
            'content' => [
                'welcomeText' => "Bienvenue Camille ! Installez-vous, le loft est à vous.\nLe café est dans le placard de gauche.",
                'wifiSsid' => 'Loft-VieuxPort', 'wifiPassword' => 'soleil-2026',
                'checkoutInfo' => "Départ avant 11 h : laissez les clés sur la table et fermez les fenêtres.\nMerci de lancer le lave-vaisselle.",
                'houseRules' => 'Pas de fête, pas de fumée, silence après 22 h.',
                'localTips' => 'Boulangerie Aux Délices (2 min) · Marché du Vieux-Port le matin · Plage des Catalans (15 min à pied).',
                'contacts' => 'Hôte : 06 00 00 00 00',
            ],
        ]);
    }

    #[Route('/api/public/demo/place/api/places/{id}/domotique', name: 'api_demo_place_domotique', methods: ['GET'])]
    public function place(string $id): JsonResponse
    {
        $this->guard();
        $minute = (int) $this->clock->now()->format('i');

        return $this->json(['sections' => [[
            'connectorId' => $id, 'name' => 'Capteurs du salon', 'pluginId' => 'demo', 'pluginName' => 'Démo', 'icon' => 'i-lucide-thermometer', 'error' => null,
            'cards' => [['title' => 'Salon', 'icon' => 'i-lucide-sofa', 'items' => [
                ['label' => 'Température', 'value' => 20.5 + ($minute % 10) / 10, 'unit' => '°C'],
                ['label' => 'Humidité', 'value' => 45 + $minute % 7, 'unit' => '%'],
                ['label' => 'CO₂', 'value' => 520 + 3 * $minute, 'unit' => 'ppm'],
                ['label' => 'Fenêtre', 'value' => false],
            ]]],
        ]]]);
    }

    #[Route('/api/public/demo/json', name: 'api_demo_json', methods: ['GET'])]
    public function feed(): JsonResponse
    {
        $this->guard();

        return $this->json([
            'office' => ['visitors' => 42, 'meetingRooms' => ['free' => 3, 'total' => 5]],
            'message' => ['body' => 'Réunion d’équipe à 14 h en salle Jupiter. Pensez à badger en sortant !'],
        ]);
    }

    private function guard(): void
    {
        if (!$this->demo) {
            throw new NotFoundHttpException();
        }
    }
}
