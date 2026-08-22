<?php

declare(strict_types=1);

namespace Pulsar\Cloud\Gcp;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Cloud\CloudException;
use Pulsar\Cloud\CloudHttpClient;
use Pulsar\Cloud\Gcp\Config\GcpConfig;
use Pulsar\Security\Session\Handler\SessionHandlerInterface;

use function count;
use function end;
use function explode;
use function json_decode;
use function json_encode;
use function rawurlencode;
use function sprintf;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * Firestore-backed session handler using the REST API.
 *
 * Stores sessions in a Firestore collection with support for
 * concurrency control, session listing, and revocation.
 */
#[Internal]
final class FirestoreSessionHandler implements SessionHandlerInterface
{
    private const string API_BASE = 'https://firestore.googleapis.com/v1';

    private ?string $contextUserId = null;
    private string $contextIpAddress = '';
    private string $contextUserAgent = '';

    public function __construct(
        private readonly GcpConfig $config,
        private readonly string $collection = 'sessions',
        private readonly int $lifetime = 7200,
        private readonly CloudHttpClient $httpClient = new CloudHttpClient(),
    ) {}

    /**
     * Set session context metadata for the next write() call.
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

        try {
            $response = $this->httpClient->request('GET', $url, $this->authHeaders());
        } catch (CloudException) {
            return '';
        }

        if ($response->statusCode === 404 || !$response->isSuccess()) {
            return '';
        }

        /** @var array{fields?: array{data?: array{stringValue?: string}}} $doc */
        $doc = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        return $doc['fields']['data']['stringValue'] ?? '';
    }

    #[Override]
    public function write(string $id, string $data): bool
    {
        $now = time();

        $fields = [
            'data' => ['stringValue' => $data],
            'last_activity' => ['integerValue' => (string) $now],
            'created_at' => ['integerValue' => (string) $now],
        ];

        if ($this->contextUserId !== null) {
            $fields['user_id'] = ['stringValue' => $this->contextUserId];
        }

        if ($this->contextIpAddress !== '') {
            $fields['ip_address'] = ['stringValue' => $this->contextIpAddress];
        }

        if ($this->contextUserAgent !== '') {
            $fields['user_agent'] = ['stringValue' => $this->contextUserAgent];
        }

        $url = $this->documentUrl($id);
        $body = json_encode(['fields' => $fields], JSON_THROW_ON_ERROR);

        $headers = $this->authHeaders();
        $headers['Content-Type'] = 'application/json';

        try {
            $response = $this->httpClient->request('PATCH', $url, $headers, $body);
        } catch (CloudException) {
            return false;
        }

        return $response->isSuccess();
    }

    #[Override]
    public function destroy(string $id): bool
    {
        $url = $this->documentUrl($id);

        try {
            $response = $this->httpClient->request('DELETE', $url, $this->authHeaders());
        } catch (CloudException) {
            return false;
        }

        return $response->isSuccess() || $response->statusCode === 404;
    }

    #[Override]
    public function gc(int $max_lifetime): int
    {
        // Firestore doesn't support server-side TTL queries via REST efficiently.
        // Use Firestore TTL policies or scheduled Cloud Functions for GC in production.
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
        $projectId = $this->config->resolveProjectId();
        $url = sprintf(
            '%s/projects/%s/databases/(default)/documents:runQuery',
            self::API_BASE,
            $projectId,
        );

        $threshold = time() - $this->lifetime;

        $query = [
            'structuredQuery' => [
                'from' => [['collectionId' => $this->collection]],
                'where' => [
                    'compositeFilter' => [
                        'op' => 'AND',
                        'filters' => [
                            [
                                'fieldFilter' => [
                                    'field' => ['fieldPath' => 'user_id'],
                                    'op' => 'EQUAL',
                                    'value' => ['stringValue' => $userId],
                                ],
                            ],
                            [
                                'fieldFilter' => [
                                    'field' => ['fieldPath' => 'last_activity'],
                                    'op' => 'GREATER_THAN_OR_EQUAL',
                                    'value' => ['integerValue' => (string) $threshold],
                                ],
                            ],
                        ],
                    ],
                ],
                'orderBy' => [
                    ['field' => ['fieldPath' => 'last_activity'], 'direction' => 'DESCENDING'],
                ],
            ],
        ];

        $body = json_encode($query, JSON_THROW_ON_ERROR);

        $headers = $this->authHeaders();
        $headers['Content-Type'] = 'application/json';

        try {
            $response = $this->httpClient->request('POST', $url, $headers, $body);
        } catch (CloudException) {
            return [];
        }

        if (!$response->isSuccess()) {
            return [];
        }

        /** @var list<array{document?: array{name: string, fields: array<string, array{stringValue?: string, integerValue?: string}>}}> $results */
        $results = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        $sessions = [];

        foreach ($results as $result) {
            if (!isset($result['document'])) {
                continue;
            }

            $doc = $result['document'];
            $fields = $doc['fields'];

            // Extract document ID from the full name path
            $nameParts = explode('/', $doc['name']);
            $docId = end($nameParts);

            $sessions[] = [
                'id' => $docId,
                'last_activity' => (int) ($fields['last_activity']['integerValue'] ?? 0),
                'ip_address' => $fields['ip_address']['stringValue'] ?? '',
                'user_agent' => $fields['user_agent']['stringValue'] ?? '',
                'created_at' => (int) ($fields['created_at']['integerValue'] ?? 0),
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
        $projectId = $this->config->resolveProjectId();

        return sprintf(
            '%s/projects/%s/databases/(default)/documents/%s/%s',
            self::API_BASE,
            $projectId,
            $this->collection,
            rawurlencode($documentId),
        );
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        $token = $this->config->resolveAccessToken();

        if ($token === '') {
            throw CloudException::authenticationFailed('gcp', 'GCP access token not configured for Firestore');
        }

        return [
            'Authorization' => 'Bearer ' . $token,
        ];
    }
}
