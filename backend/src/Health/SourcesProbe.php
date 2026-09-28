<?php

namespace App\Health;

use App\Repository\SourceRepository;
use App\Source\SourceFetcher;
use Doctrine\ORM\EntityManagerInterface;
use Rocket\Core\Health\ServiceProbeInterface;

/** The enabled sources on the dashboard, fetched every 5 minutes by the worker (which also refreshes their payload). */
final class SourcesProbe implements ServiceProbeInterface
{
    public function __construct(
        private readonly SourceRepository $sources,
        private readonly SourceFetcher $fetcher,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function id(): string
    {
        return 'sources';
    }

    public function label(): string
    {
        return 'Sources des écrans';
    }

    public function targets(): iterable
    {
        foreach ($this->sources->findBy(['enabled' => true], ['name' => 'ASC']) as $source) {
            yield $source->getId()->toRfc4122() => [
                'name' => $source->getName(),
                'check' => function () use ($source): string {
                    $payload = $this->fetcher->payload($source, true);
                    $this->em->flush();
                    if (null !== $source->getLastError()) {
                        throw new \RuntimeException($source->getLastError());
                    }

                    return \sprintf('%d donnée(s)', \count($payload ?? []));
                },
            ];
        }
    }
}
