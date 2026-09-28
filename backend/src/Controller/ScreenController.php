<?php

namespace App\Controller;

use App\Cast\CastViews;
use App\Cast\JsonBody;
use App\Entity\PairingRequest;
use App\Entity\Playlist;
use App\Entity\Screen;
use App\Repository\PairingRequestRepository;
use App\Repository\PlaylistRepository;
use App\Repository\ScreenRepository;
use App\Secret\SecretStoreInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/** Screens: CRUD, kiosk link (shown once: create, rotate, pairing), revocation. */
#[IsGranted('ROLE_USER')]
final class ScreenController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ScreenRepository $screens,
        private readonly PlaylistRepository $playlists,
        private readonly CastViews $views,
        private readonly ClockInterface $clock,
        #[Autowire(env: 'FRONTEND_URL')] private readonly string $frontendUrl,
    ) {
    }

    #[Route('/api/screens', name: 'api_screens', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $now = $this->clock->now();

        return $this->json(array_map(fn (Screen $s) => $this->views->screen($s, $now), $this->screens->findBy([], ['name' => 'ASC'])));
    }

    #[Route('/api/screens/{id}', name: 'api_screen', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function show(#[MapEntity] Screen $screen): JsonResponse
    {
        return $this->json($this->views->screen($screen, $this->clock->now()));
    }

    #[Route('/api/screens', name: 'api_screen_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $screen = new Screen();
        $this->apply($screen, JsonBody::of($request), true);
        $token = $screen->rotateToken();
        $this->em->persist($screen);
        $this->em->flush();

        return $this->json($this->views->screen($screen, $this->clock->now()) + $this->link($token), Response::HTTP_CREATED);
    }

    #[Route('/api/screens/{id}', name: 'api_screen_update', methods: ['PATCH'], requirements: ['id' => Requirement::UUID])]
    public function update(#[MapEntity] Screen $screen, Request $request): JsonResponse
    {
        $this->apply($screen, JsonBody::of($request), false);
        $this->em->flush();

        return $this->json($this->views->screen($screen, $this->clock->now()));
    }

    #[Route('/api/screens/{id}', name: 'api_screen_delete', methods: ['DELETE'], requirements: ['id' => Requirement::UUID])]
    public function delete(#[MapEntity] Screen $screen): Response
    {
        $this->em->remove($screen);
        $this->em->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** New kiosk link: the previous one stops working. The link is only shown in this response. */
    #[Route('/api/screens/{id}/token', name: 'api_screen_token_rotate', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function rotate(#[MapEntity] Screen $screen): JsonResponse
    {
        $token = $screen->rotateToken();
        $this->em->flush();

        return $this->json($this->views->screen($screen, $this->clock->now()) + $this->link($token));
    }

    #[Route('/api/screens/{id}/token', name: 'api_screen_token_revoke', methods: ['DELETE'], requirements: ['id' => Requirement::UUID])]
    public function revoke(#[MapEntity] Screen $screen): JsonResponse
    {
        $screen->revokeToken();
        $this->em->flush();

        return $this->json($this->views->screen($screen, $this->clock->now()));
    }

    /**
     * Links a fresh screen showing a pairing code (/pair): to an existing screen ("screenId", its link is rotated) or
     * to a new one ("name" and the other settings). The screen then receives its kiosk link by itself.
     */
    #[Route('/api/screens/pair', name: 'api_screen_pair', methods: ['POST'])]
    public function pair(Request $request, PairingRequestRepository $pairings, SecretStoreInterface $secrets): JsonResponse
    {
        $data = JsonBody::of($request);
        $pairing = $pairings->findOpenByCode((string) ($data['code'] ?? ''), $this->clock->now());
        if (null === $pairing) {
            throw new UnprocessableEntityHttpException('Code inconnu ou expiré : vérifiez le code affiché par l’écran.');
        }
        $created = false;
        if (null !== ($data['screenId'] ?? null)) {
            $screen = Uuid::isValid((string) $data['screenId']) ? $this->screens->find((string) $data['screenId']) : null;
            if (null === $screen) {
                throw new NotFoundHttpException('Écran introuvable.');
            }
        } else {
            $screen = new Screen();
            $this->apply($screen, $data + ['name' => 'Écran '.$pairing->getCode()], true);
            $this->em->persist($screen);
            $created = true;
        }
        $token = $screen->rotateToken();
        $pairing->pair($screen, $secrets->seal(['token' => $token]));
        $this->em->flush();

        return $this->json($this->views->screen($screen, $this->clock->now()) + ['paired' => true], $created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /** @param array<string, mixed> $data */
    private function apply(Screen $screen, array $data, bool $creating): void
    {
        if ($creating || \array_key_exists('name', $data)) {
            $name = trim((string) ($data['name'] ?? ''));
            if ('' === $name || mb_strlen($name) > 120) {
                throw new UnprocessableEntityHttpException('Nom obligatoire (120 caractères au plus).');
            }
            $screen->setName($name);
        }
        if (\array_key_exists('location', $data)) {
            $screen->setLocation(null === $data['location'] ? null : mb_substr((string) $data['location'], 0, 255));
        }
        if (\array_key_exists('orientation', $data)) {
            if (!\in_array($data['orientation'], Screen::ORIENTATIONS, true)) {
                throw new UnprocessableEntityHttpException('Orientation : landscape ou portrait.');
            }
            $screen->setOrientation($data['orientation']);
        }
        if (\array_key_exists('timezone', $data)) {
            if (!\is_string($data['timezone']) || !\in_array($data['timezone'], \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
                throw new UnprocessableEntityHttpException('Fuseau horaire inconnu (ex. Europe/Paris).');
            }
            $screen->setTimezone($data['timezone']);
        }
        if (\array_key_exists('locale', $data)) {
            if (!\is_string($data['locale']) || !preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $data['locale'])) {
                throw new UnprocessableEntityHttpException('Langue : fr-FR, en-GB…');
            }
            $screen->setLocale($data['locale']);
        }
        if (\array_key_exists('enabled', $data)) {
            $screen->setEnabled((bool) $data['enabled']);
        }
        if (\array_key_exists('playlistId', $data)) {
            $playlist = null;
            if (null !== $data['playlistId'] && '' !== $data['playlistId']) {
                $playlist = Uuid::isValid((string) $data['playlistId']) ? $this->playlists->find((string) $data['playlistId']) : null;
                if (!$playlist instanceof Playlist) {
                    throw new UnprocessableEntityHttpException('Playlist introuvable.');
                }
            }
            $screen->setPlaylist($playlist);
        }
    }

    /** @return array{token: string, kioskPath: string, kioskUrl: string} */
    private function link(string $token): array
    {
        return ['token' => $token, 'kioskPath' => '/s/'.$token, 'kioskUrl' => rtrim($this->frontendUrl, '/').'/s/'.$token];
    }
}
