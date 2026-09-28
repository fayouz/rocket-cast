<?php

namespace App\Controller;

use App\Cast\CastViews;
use App\Cast\JsonBody;
use App\Entity\Source;
use App\Repository\SourceRepository;
use App\Secret\SecretException;
use App\Secret\SecretStoreInterface;
use App\Source\SourceException;
use App\Source\SourceFetcher;
use App\Source\SourceRegistry;
use App\Source\SourceTypeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Sources: everyone lists them (to build source panels), administrators manage them. Credentials are sealed
 * (SecretStoreInterface) and never returned: "secrets" says which ones are set. In a PATCH, an absent or null
 * secret keeps the current value, "" removes it.
 */
#[IsGranted('ROLE_USER')]
final class SourceController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SourceRepository $sources,
        private readonly SourceRegistry $registry,
        private readonly SecretStoreInterface $secrets,
        private readonly CastViews $views,
    ) {
    }

    #[Route('/api/source-types', name: 'api_source_types', methods: ['GET'])]
    public function types(): JsonResponse
    {
        return $this->json(array_values(array_map(fn (SourceTypeInterface $t) => $this->registry->describe($t), $this->registry->all())));
    }

    #[Route('/api/sources', name: 'api_sources', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $admin = $this->isGranted('ROLE_ADMIN');

        return $this->json(array_map(fn (Source $s) => $this->views->source($s, $admin), $this->sources->findBy([], ['name' => 'ASC'])));
    }

    #[Route('/api/sources/{id}', name: 'api_source', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function show(#[MapEntity] Source $source): JsonResponse
    {
        return $this->json($this->views->source($source, $this->isGranted('ROLE_ADMIN')));
    }

    /** Last payload fetched (what the screens show), without fetching. */
    #[Route('/api/sources/{id}/payload', name: 'api_source_payload', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function payload(#[MapEntity] Source $source): JsonResponse
    {
        return $this->json(['payload' => $source->getPayload(), 'fetchedAt' => $source->getFetchedAt()?->format(\DATE_ATOM), 'lastError' => $source->getLastError()]);
    }

    #[Route('/api/sources', name: 'api_source_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(Request $request): JsonResponse
    {
        $data = JsonBody::of($request);
        $type = (string) ($data['type'] ?? '');
        if (!$this->registry->has($type)) {
            throw new UnprocessableEntityHttpException('Type de source inconnu.');
        }
        $source = (new Source())->setType($type);
        $this->apply($source, $data, true);
        $this->em->persist($source);
        $this->em->flush();

        return $this->json($this->views->source($source, true), Response::HTTP_CREATED);
    }

    #[Route('/api/sources/{id}', name: 'api_source_update', methods: ['PATCH'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(#[MapEntity] Source $source, Request $request): JsonResponse
    {
        $this->apply($source, JsonBody::of($request), false);
        $this->em->flush();

        return $this->json($this->views->source($source, true));
    }

    #[Route('/api/sources/{id}', name: 'api_source_delete', methods: ['DELETE'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(#[MapEntity] Source $source): Response
    {
        $this->em->remove($source);
        $this->em->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** Fetches the source now and returns what it gives (or the error). */
    #[Route('/api/sources/{id}/test', name: 'api_source_test', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('ROLE_ADMIN')]
    public function test(#[MapEntity] Source $source, SourceFetcher $fetcher): JsonResponse
    {
        $start = hrtime(true);
        $payload = $fetcher->payload($source, true);
        $this->em->flush();
        $ok = null === $source->getLastError();

        return $this->json([
            'ok' => $ok,
            'error' => $source->getLastError(),
            'latencyMs' => (int) ((hrtime(true) - $start) / 1_000_000),
            'payload' => $ok ? $payload : null,
            'source' => $this->views->source($source, true),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function apply(Source $source, array $data, bool $creating): void
    {
        $type = $this->registry->get($source->getType());
        if ($creating || \array_key_exists('name', $data)) {
            $name = trim((string) ($data['name'] ?? ''));
            if ('' === $name || mb_strlen($name) > 120) {
                throw new UnprocessableEntityHttpException('Nom obligatoire (120 caractères au plus).');
            }
            $source->setName($name);
        }
        if (\array_key_exists('enabled', $data)) {
            $source->setEnabled((bool) $data['enabled']);
        }
        if (\array_key_exists('refreshSeconds', $data)) {
            $refresh = $data['refreshSeconds'];
            if (!\is_int($refresh) || $refresh < 30 || $refresh > 86400) {
                throw new UnprocessableEntityHttpException('Rafraîchissement : entre 30 secondes et 24 heures.');
            }
            $source->setRefreshSeconds($refresh);
        }
        $changed = false;
        if ($creating || \array_key_exists('config', $data)) {
            try {
                $source->setConfig($type->normaliseConfig(\is_array($data['config'] ?? null) ? $data['config'] : []));
            } catch (SourceException $e) {
                throw new UnprocessableEntityHttpException($e->getMessage());
            }
            $changed = true;
        }
        $secretFields = array_values(array_filter($type->fields(), static fn (array $f) => $f['secret'] ?? false));
        if ($creating || \is_array($data['secrets'] ?? null)) {
            try {
                $current = $creating ? [] : $this->secrets->open($source->getSealedSecrets());
            } catch (SecretException) {
                $current = [];
            }
            $submitted = \is_array($data['secrets'] ?? null) ? $data['secrets'] : [];
            foreach ($secretFields as $field) {
                $value = $submitted[$field['key']] ?? null;
                if (\is_string($value)) {
                    if ('' === $value) {
                        unset($current[$field['key']]);
                    } else {
                        $current[$field['key']] = mb_substr($value, 0, 4000);
                    }
                    $changed = true;
                }
            }
            foreach ($secretFields as $field) {
                if (($field['required'] ?? false) && '' === ($current[$field['key']] ?? '')) {
                    throw new UnprocessableEntityHttpException(\sprintf('« %s » est obligatoire.', $field['label']));
                }
            }
            $source->setSealedSecrets($this->secrets->seal($current));
        }
        if ($changed) {
            $source->resetPayload();
        }
    }
}
