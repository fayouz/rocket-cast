<?php

namespace App\Controller;

use App\Cast\JsonBody;
use App\Cast\KioskPayload;
use App\Cast\PublicRateLimiter;
use App\Entity\PairingRequest;
use App\Repository\PairingRequestRepository;
use App\Repository\ScreenRepository;
use App\Secret\SecretException;
use App\Secret\SecretStoreInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public endpoints of the screens (no account): the kiosk payload (/s/<token> polls it every minute and at
 * reloadAt, which is also the heartbeat), and the pairing of a fresh screen (/pair). Rate-limited per IP,
 * never cached, never indexed.
 */
final class KioskController extends AbstractController
{
    /** lastSeenAt is written at most every 30 seconds per screen. */
    private const HEARTBEAT_SECONDS = 30;

    public function __construct(
        private readonly PublicRateLimiter $limiter,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/api/public/screens/{token}', name: 'api_public_screen', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_-]{43}'])]
    public function screen(string $token, Request $request, ScreenRepository $screens, KioskPayload $kiosk): JsonResponse
    {
        $this->limiter->hit($request);
        $screen = $screens->findOneByToken($token);
        if (null === $screen) {
            $this->limiter->failure($request);
            throw new NotFoundHttpException('Lien d’écran invalide ou révoqué.');
        }
        $now = $this->clock->now();
        $seen = $screen->getLastSeenAt();
        if (null === $seen || $now->getTimestamp() - $seen->getTimestamp() >= self::HEARTBEAT_SECONDS) {
            $screen->seen($now, $request->headers->get('User-Agent'));
        }
        $payload = $kiosk->forScreen($screen, $now);
        $this->em->flush();

        return $this->publicJson($payload);
    }

    /** A fresh screen asks for a pairing code. Keep "secret": it is the only way to poll. */
    #[Route('/api/public/pairings', name: 'api_public_pairing_create', methods: ['POST'])]
    public function createPairing(Request $request, PairingRequestRepository $pairings, SecretStoreInterface $secrets): JsonResponse
    {
        $this->limiter->hit($request);
        // Creating codes counts as a failure: at most 10 a minute per IP.
        $this->limiter->failure($request);
        $now = $this->clock->now();
        $pairings->purgeExpired($now, $secrets);
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $pairing = new PairingRequest($now, $secret, $request->headers->get('User-Agent'));
        $this->em->persist($pairing);
        $this->em->flush();

        return $this->publicJson([
            'code' => substr($pairing->getCode(), 0, 3).'-'.substr($pairing->getCode(), 3),
            'secret' => $secret,
            'expiresAt' => $pairing->getExpiresAt()->format(\DATE_ATOM),
            'pollSeconds' => 3,
        ], Response::HTTP_CREATED);
    }

    /** Body {secret}: "pending", or "paired" with the kiosk token, delivered once. */
    #[Route('/api/public/pairings/poll', name: 'api_public_pairing_poll', methods: ['POST'])]
    public function pollPairing(Request $request, PairingRequestRepository $pairings, SecretStoreInterface $secrets): JsonResponse
    {
        $this->limiter->hit($request);
        $secret = (string) (JsonBody::of($request)['secret'] ?? '');
        $pairing = '' === $secret ? null : $pairings->findOneBySecret($secret);
        if (null === $pairing || $pairing->isExpired($this->clock->now())) {
            $this->limiter->failure($request);
            throw new NotFoundHttpException('Demande d’appairage inconnue ou expirée : un nouveau code va être affiché.');
        }
        if (null === $pairing->getScreen() || null === $pairing->getSealedToken()) {
            return $this->publicJson(['status' => 'pending', 'expiresAt' => $pairing->getExpiresAt()->format(\DATE_ATOM)]);
        }
        try {
            $token = $secrets->open($pairing->getSealedToken())['token'] ?? '';
        } catch (SecretException) {
            $token = '';
        }
        $name = $pairing->getScreen()->getName();
        $sealed = $pairing->getSealedToken();
        $this->em->remove($pairing);
        $this->em->flush();
        $secrets->forget($sealed);
        if ('' === $token) {
            throw new NotFoundHttpException('Appairage à refaire.');
        }

        return $this->publicJson(['status' => 'paired', 'token' => $token, 'path' => '/s/'.$token, 'screen' => $name]);
    }

    /** @param array<string, mixed> $data */
    private function publicJson(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = $this->json($data, $status);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
