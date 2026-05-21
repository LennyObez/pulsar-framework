<?php

declare(strict_types=1);

namespace Pulsar\Cloud\Azure;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Cloud\Azure\Config\AzureConfig;
use Pulsar\Cloud\CloudException;
use Pulsar\Cloud\CloudHttpClient;
use Pulsar\Security\Session\Handler\SessionHandlerInterface;

use function count;
use function json_decode;
use function json_encode;
use function rawurlencode;
use function sprintf;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * Azure Cosmos DB session handler using the REST API.
 *
 * Stores sessions in a Cosmos DB container with support for
 * concurrency control, session listing, and revocation.
 * Uses the Cosmos DB SQL API (Core API) with OAuth2 tokens.
 */
#[Internal]
final class CosmosDbSessionHandler implements SessionHandlerInterface
{
    private ?string $contextUserId = null;
    private string $contextIpAddress = '';
    private string $contextUserAgent = '';

    public function __construct(
        private readonly AzureConfig $config,
        private readonly string $accountName,
        private readonly string $databaseId,
        private readonly string $containerId = 'sessions',
        private readonly int $lifetime = 7200,
        private readonly CloudHttpClient $httpClient = new CloudHttpClient(),
    ) {}

    /**
     * Set session context metadata for the next write() call.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function setSessionContext(
        ?string $userId,
        string $ipAddress,
        string $userAgent,
    ): void {
        $this->contextUserId = $userId;
        $this->contextIpAddress = $ipAddress;
        $this->contextUserAgent = $userAgent;
    }

    #[Override]
    public function open(string $path, string $name): bool
    {
        return true;
    }

    #[Override]
    public function close(): bool
    {
        return true;
    }

    #[Override]
    public function read(string $id): string
    {
        $url = $this->documentUrl($id);

        $headers = $this->authHeaders();
        $headers['x-ms-documentdb-partitionkey'] = sprintf('["%s"]', $id);

        try {
            $response = $this->httpClient->request('GET', $url, $headers);
        } catch (CloudException) {
            return '';
        }

        if ($response->statusCode === 404 || !$response->isSuccess()) {
            return '';
        }

        /** @var array{data?: string} $doc */
        $doc = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        return $doc['data'] ?? '';
    }

    #[Override]
    public function write(string $id, string $data): bool
    {
        $now = time();

        /** @var array<string, mixed> $document */
        $document = [
            'id' => $id,
            'data' => $data,
            'last_activity' => $now,
            'created_at' => $now,
            'ttl' => $this->lifetime,
        ];

        if ($this->contextUserId !== null) {
            $document['user_id'] = $this->contextUserId;
        }

        if ($this->contextIpAddress !== '') {
            $document['ip_address'] = $this->contextIpAddress;
        }

        if ($this->contextUserAgent !== '') {
            $document['user_agent'] = $this->contextUserAgent;
        }

        $body = json_encode($document, JSON_THROW_ON_ERROR);
        $url = $this->collectionUrl() . '/docs';

        $headers = $this->authHeaders();
        $headers['Content-Type'] = 'application/json';
        $headers['x-ms-documentdb-is-upsert'] = 'True';
        $headers['x-ms-documentdb-partitionkey'] = sprintf('["%s"]', $id);

        try {
            $response = $this->httpClient->request('POST', $url, $headers, $body);
        } catch (CloudException) {
            return false;
        }

        return $response->isSuccess() || $response->statusCode === 201;
    }

    #[Override]
    public function destroy(string $id): bool
    {
        $url = $this->documentUrl($id);

        $headers = $this->authHeaders();
        $headers['x-ms-documentdb-partitionkey'] = sprintf('["%s"]', $id);

        try {
            $response = $this->httpClient->request('DELETE', $url, $headers);
        } catch (CloudException) {
            return false;
        }

        return $response->isSuccess() || $response->statusCode === 204 || $response->statusCode === 404;
    }

    #[Override]
    public function gc(int $max_lifetime): int
    {
        // Cosmos DB handles TTL-based expiration automatically via the ttl field.
        // No manual GC needed when TTL is enabled on the container.
        return 0;
    }

    #[Override]
    public function supportsConcurrencyControl(): bool
    {
        return true;
    }

    #[Override]
    public function supportsSessionListing(): bool
    {
        return true;
    }

    #[Override]
    public function supportsRevocation(): bool
    {
        return true;
    }

    #[Override]
    public function getActiveSessions(string $userId): int
    {
        $sessions = $this->listSessions($userId);

        return count($sessions);
    }

    /**
     * @return list<array{id: string, last_activity: int, ip_address: string, user_agent: string, created_at: int}>
     */
    #[Override]
    public function listSessions(string $userId): array
    {
        $threshold = time() - $this->lifetime;
        $url = $this->collectionUrl() . '/docs';

        $query = sprintf(
            "SELECT * FROM c WHERE c.user_id = '%s' AND c.last_activity >= %d ORDER BY c.last_activity DESC",
            $userId,
            $threshold,
        );

        $body = json_encode([
            'query' => $query,
            'parameters' => [],
        ], JSON_THROW_ON_ERROR);

        $headers = $this->authHeaders();
        $headers['Content-Type'] = 'application/query+json';
        $headers['x-ms-documentdb-isquery'] = 'True';
        $headers['x-ms-documentdb-query-enablecrosspartition'] = 'True';

        try {
            $response = $this->httpClient->request('POST', $url, $headers, $body);
        } catch (CloudException) {
            return [];
        }

        if (!$response->isSuccess()) {
            return [];
        }

        /** @var array{Documents?: list<array{id: string, last_activity?: int, ip_address?: string, user_agent?: string, created_at?: int}>} $result */
        $result = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        $sessions = [];

        foreach ($result['Documents'] ?? [] as $doc) {
            $sessions[] = [
                'id' => $doc['id'],
                'last_activity' => $doc['last_activity'] ?? 0,
                'ip_address' => $doc['ip_address'] ?? '',
                'user_agent' => $doc['user_agent'] ?? '',
                'created_at' => $doc['created_at'] ?? 0,
            ];
        }

        return $sessions;
    }

    #[Override]
    public function revokeSession(string $sessionId): bool
    {
        return $this->destroy($sessionId);
    }

    private function documentUrl(string $documentId): string
    {
        return sprintf(
            '%s/docs/%s',
            $this->collectionUrl(),
            rawurlencode($documentId),
        );
    }

    private function collectionUrl(): string
    {
        $baseUrl = $this->config->endpoint
            ?? sprintf('https://%s.documents.azure.com', $this->accountName);

        return sprintf(
            '%s/dbs/%s/colls/%s',
            $baseUrl,
            rawurlencode($this->databaseId),
            rawurlencode($this->containerId),
        );
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        $token = $this->config->resolveAccessToken();

        if ($token === '') {
            throw CloudException::authenticationFailed('azure', 'Azure access token not configured for Cosmos DB');
        }

        return [
            'Authorization' => 'Bearer ' . $token,
            'x-ms-version' => '2018-12-31',
        ];
    }
}
