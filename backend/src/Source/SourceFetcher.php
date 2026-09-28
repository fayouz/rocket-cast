<?php

namespace App\Source;

use App\Entity\Source;
use App\Secret\SecretStoreInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * The payload of a source, fetched again when older than its refresh interval (or past its reloadAt). A failure keeps
 * the last payload (the screens go on showing it) and is retried a minute later at the soonest. The caller flushes.
 */
final class SourceFetcher
{
    private const RETRY_SECONDS = 60;

    public function __construct(
        private readonly SourceRegistry $registry,
        private readonly SecretStoreInterface $secrets,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function payload(Source $source, bool $force = false): ?array
    {
        $now = $this->clock->now();
        if (!$force && !$this->isStale($source, $now)) {
            return $source->getPayload();
        }
        try {
            $payload = $this->registry->get($source->getType())->fetch($source->getConfig(), $this->secrets->open($source->getSealedSecrets()));
            $source->fetched($payload, $now);
        } catch (\Throwable $e) {
            $this->logger->warning('Source {name} unreadable: {error}', ['name' => $source->getName(), 'error' => $e->getMessage()]);
            $source->failed($e instanceof SourceException || $e instanceof \App\Secret\SecretException ? $e->getMessage() : 'Erreur inattendue : '.$e->getMessage(), $now);
        }

        return $source->getPayload();
    }

    private function isStale(Source $source, \DateTimeImmutable $now): bool
    {
        $attempted = $source->getAttemptedAt();
        if (null !== $source->getLastError() && null !== $attempted && $now->getTimestamp() - $attempted->getTimestamp() < self::RETRY_SECONDS) {
            return false;
        }
        $fetched = $source->getFetchedAt();
        if (null === $fetched || $now->getTimestamp() - $fetched->getTimestamp() >= $source->getRefreshSeconds()) {
            return true;
        }
        $reloadAt = self::reloadAt($source->getPayload());

        return null !== $reloadAt && $reloadAt <= $now && $reloadAt > $fetched;
    }

    /** @param array<string, mixed>|null $payload */
    public static function reloadAt(?array $payload): ?\DateTimeImmutable
    {
        $value = $payload['reloadAt'] ?? null;
        if (!\is_string($value) || '' === $value) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
