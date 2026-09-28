<?php

namespace App\Source;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** JSON GET requests of the sources: short timeout (CAST_SOURCE_TIMEOUT), 2 MiB at most, clear errors. */
final class SourceHttp
{
    private const MAX_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire(env: 'float:CAST_SOURCE_TIMEOUT')] private readonly float $timeout = 5.0,
    ) {
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<mixed>
     */
    public function getJson(string $url, array $headers = []): array
    {
        if (!preg_match('#^https?://#i', $url)) {
            throw new SourceException('Adresse invalide : http:// ou https:// attendu.');
        }
        try {
            $response = $this->http->request('GET', $url, [
                'headers' => ['Accept' => 'application/json'] + $headers,
                'timeout' => $this->timeout,
                'max_duration' => $this->timeout * 2,
                'max_redirects' => 3,
            ]);
            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new SourceException(match (true) {
                    401 === $status, 403 === $status => \sprintf('Accès refusé (HTTP %d) : vérifiez le jeton.', $status),
                    404 === $status => 'Introuvable (HTTP 404) : vérifiez l’adresse ou le jeton.',
                    429 === $status => 'Trop de requêtes (HTTP 429) : augmentez l’intervalle de rafraîchissement.',
                    default => \sprintf('La source a répondu HTTP %d.', $status),
                });
            }
            $body = $response->getContent();
        } catch (ExceptionInterface $e) {
            throw new SourceException('Source injoignable : '.$e->getMessage(), 0, $e);
        }
        if (\strlen($body) > self::MAX_BYTES) {
            throw new SourceException('Réponse trop volumineuse (2 Mio au plus).');
        }
        try {
            $data = json_decode($body, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new SourceException('La réponse n’est pas du JSON.');
        }
        if (!\is_array($data)) {
            throw new SourceException('La réponse JSON n’est ni un objet ni une liste.');
        }

        return $data;
    }

    public static function baseUrl(mixed $url, string $label = 'Adresse'): string
    {
        $url = rtrim(trim((string) $url), '/');
        if (!preg_match('#^https?://[^\s/?\#]+(/[^\s?\#]*)?$#i', $url)) {
            throw new SourceException($label.' : une adresse http:// ou https:// est attendue.');
        }

        return $url;
    }
}
