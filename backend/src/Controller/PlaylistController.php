<?php

namespace App\Controller;

use App\Cast\CastViews;
use App\Cast\JsonBody;
use App\Cast\KioskPayload;
use App\Cast\PanelException;
use App\Cast\Panels;
use App\Entity\Playlist;
use App\Entity\Screen;
use App\Repository\PlaylistRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Playlists: CRUD, duplication, live preview of panels being edited. */
#[IsGranted('ROLE_USER')]
final class PlaylistController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlaylistRepository $playlists,
        private readonly Panels $panels,
        private readonly CastViews $views,
    ) {
    }

    #[Route('/api/playlists', name: 'api_playlists', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $counts = $this->screenCounts();

        return $this->json(array_map(fn (Playlist $p) => $this->views->playlist($p, $counts[$p->getId()->toRfc4122()] ?? 0, false), $this->playlists->findBy([], ['name' => 'ASC'])));
    }

    #[Route('/api/playlists/{id}', name: 'api_playlist', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function show(#[MapEntity] Playlist $playlist): JsonResponse
    {
        return $this->json($this->views->playlist($playlist, $this->screenCounts()[$playlist->getId()->toRfc4122()] ?? 0));
    }

    #[Route('/api/playlists', name: 'api_playlist_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $playlist = new Playlist();
        $this->apply($playlist, JsonBody::of($request), true);
        $this->em->persist($playlist);
        $this->em->flush();

        return $this->json($this->views->playlist($playlist, 0), Response::HTTP_CREATED);
    }

    #[Route('/api/playlists/{id}', name: 'api_playlist_update', methods: ['PATCH', 'PUT'], requirements: ['id' => Requirement::UUID])]
    public function update(#[MapEntity] Playlist $playlist, Request $request): JsonResponse
    {
        $this->apply($playlist, JsonBody::of($request), false);
        $this->em->flush();

        return $this->show($playlist);
    }

    #[Route('/api/playlists/{id}', name: 'api_playlist_delete', methods: ['DELETE'], requirements: ['id' => Requirement::UUID])]
    public function delete(#[MapEntity] Playlist $playlist): Response
    {
        $this->em->createQuery('UPDATE App\Entity\Screen s SET s.playlist = NULL WHERE s.playlist = :p')->setParameter('p', $playlist->getId(), 'uuid')->execute();
        $this->em->remove($playlist);
        $this->em->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/playlists/{id}/duplicate', name: 'api_playlist_duplicate', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function duplicate(#[MapEntity] Playlist $playlist): JsonResponse
    {
        $copy = (new Playlist())
            ->setName(mb_substr($playlist->getName().' (copie)', 0, 120))
            ->setDescription($playlist->getDescription())
            ->setAccent($playlist->getAccent())
            ->setTheme($playlist->getTheme())
            ->setPanels($playlist->getPanels());
        $this->em->persist($copy);
        $this->em->flush();

        return $this->json($this->views->playlist($copy, 0), Response::HTTP_CREATED);
    }

    /**
     * Live preview of the editor: the panels as submitted (not saved), resolved as a screen would show them, with
     * "activeNow" for the schedule. Body: {panels, timezone?}.
     */
    #[Route('/api/playlists/preview', name: 'api_playlist_preview', methods: ['POST'])]
    public function preview(Request $request, KioskPayload $kiosk, ClockInterface $clock): JsonResponse
    {
        $data = JsonBody::of($request);
        $timezone = \is_string($data['timezone'] ?? null) && \in_array($data['timezone'], \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true) ? $data['timezone'] : 'Europe/Paris';
        try {
            $panels = $this->panels->normalise($data['panels'] ?? []);
        } catch (PanelException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage());
        }

        return $this->json($kiosk->preview($panels, $timezone, $clock->now()));
    }

    /** @param array<string, mixed> $data */
    private function apply(Playlist $playlist, array $data, bool $creating): void
    {
        if ($creating || \array_key_exists('name', $data)) {
            $name = trim((string) ($data['name'] ?? ''));
            if ('' === $name || mb_strlen($name) > 120) {
                throw new UnprocessableEntityHttpException('Nom obligatoire (120 caractères au plus).');
            }
            $playlist->setName($name);
        }
        if (\array_key_exists('description', $data)) {
            $playlist->setDescription(null === $data['description'] ? null : mb_substr((string) $data['description'], 0, 1000));
        }
        if (\array_key_exists('accent', $data)) {
            if (!\is_string($data['accent']) || !preg_match('/^#[0-9a-fA-F]{6}$/', $data['accent'])) {
                throw new UnprocessableEntityHttpException('Couleur : #RRGGBB.');
            }
            $playlist->setAccent(strtolower($data['accent']));
        }
        if (\array_key_exists('theme', $data)) {
            if (!\in_array($data['theme'], ['dark', 'light'], true)) {
                throw new UnprocessableEntityHttpException('Thème : dark ou light.');
            }
            $playlist->setTheme($data['theme']);
        }
        if (\array_key_exists('panels', $data)) {
            try {
                $playlist->setPanels($this->panels->normalise($data['panels']));
            } catch (PanelException $e) {
                throw new UnprocessableEntityHttpException($e->getMessage());
            }
        }
    }

    /** @return array<string, int> */
    private function screenCounts(): array
    {
        $counts = [];
        foreach ($this->em->createQuery('SELECT IDENTITY(s.playlist) AS p, COUNT(s.id) AS n FROM '.Screen::class.' s WHERE s.playlist IS NOT NULL GROUP BY s.playlist')->getArrayResult() as $row) {
            $counts[(string) $row['p']] = (int) $row['n'];
        }

        return $counts;
    }
}
