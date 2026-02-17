<?php

declare(strict_types=1);

namespace Pulsar\Cloud\Gcp;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Cloud\CloudException;
use Pulsar\Cloud\CloudHttpClient;
use Pulsar\Cloud\Gcp\Config\GcpConfig;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueDriverInterface;
use Random\Engine\Secure;
use Random\Randomizer;

use function base64_decode;
use function base64_encode;
use function bin2hex;
use function gmdate;
use function json_decode;
use function json_encode;
use function sprintf;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * Google Cloud Pub/Sub queue driver using raw HTTP with OAuth2 tokens.
 *
 * Maps Pulsar queue names to Pub/Sub topics and subscriptions.
 * Uses the Pub/Sub REST API directly without the Google Cloud SDK.
 */
#[Internal(reason: 'Implementation detail; use QueueDriverInterface contract')]
final class PubSubQueueDriver implements QueueDriverInterface
{
    private const string API_BASE = 'https://pubsub.googleapis.com/v1';

    private readonly Randomizer $randomizer;

    /** @var array<string, string> Ack ID indexed by job ID */
    private array $ackIds = [];

    /** @var array<string, string> Subscription path indexed by job ID */
    private array $jobSubscriptions = [];

    /** @var array<string, JobRecord> In-memory tracking for findByStatus */
    private array $knownJobs = [];

    public function __construct(
        private readonly GcpConfig $config,
        private readonly string $topicPrefix = 'pulsar-queue-',
        private readonly string $subscriptionPrefix = 'pulsar-worker-',
        private readonly CloudHttpClient $httpClient = new CloudHttpClient(),
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    #[Override]
    public function push(string $queue, string $jobClass, string $payload, int $delay = 0): string
    {
        $id = bin2hex($this->randomizer->getBytes(16));
        $now = time();

        $jobData = json_encode([
            'id' => $id,
            'queue' => $queue,
            'job_class' => $jobClass,
            'payload' => $payload,
            'attempts' => 0,
            'status' => JobRecordStatus::Pending->value,
            'created_at' => $now,
            'available_at' => $now + $delay,
        ], JSON_THROW_ON_ERROR);

        $topicPath = $this->topicPath($queue);
        $url = sprintf('%s/%s:publish', self::API_BASE, $topicPath);

        $requestBody = json_encode([
            'messages' => [
                [
                    'data' => base64_encode($jobData),
                    'attributes' => [
                        'job_id' => $id,
                        'available_at' => (string) ($now + $delay),
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->pubSubRequest('POST', $url, $requestBody);

        $this->knownJobs[$id] = new JobRecord(
            id: $id,
            queue: $queue,
            jobClass: $jobClass,
            payload: $payload,
            attempts: 0,
            status: JobRecordStatus::Pending,
            createdAt: $now,
            availableAt: $now + $delay,
        );

        return $id;
    }

    #[Override]
    public function pop(string $queue): ?JobRecord
    {
        $subscriptionPath = $this->subscriptionPath($queue);
        $url = sprintf('%s/%s:pull', self::API_BASE, $subscriptionPath);

        $requestBody = json_encode([
            'maxMessages' => 1,
        ], JSON_THROW_ON_ERROR);

        $responseBody = $this->pubSubRequest('POST', $url, $requestBody);

        /** @var array{receivedMessages?: list<array{ackId: string, message: array{data: string, attributes?: array<string, string>}}>} $response */
        $response = json_decode($responseBody, true, flags: JSON_THROW_ON_ERROR);

        if (!isset($response['receivedMessages']) || $response['receivedMessages'] === []) {
            return null;
        }

        $received = $response['receivedMessages'][0];
        $ackId = $received['ackId'];
        $body = base64_decode($received['message']['data'], true);

        if ($body === false) {
            return null;
        }

        $attributes = $received['message']['attributes'] ?? [];
        $availableAt = (int) ($attributes['available_at'] ?? 0);
        $now = time();

        // Delayed job not yet ready: nack it
        if ($availableAt > $now) {
            $this->modifyAckDeadline($subscriptionPath, $ackId, 0);

            return null;
        }

        /** @var array{id: string, queue: string, job_class: string, payload: string, attempts: int, status: string, created_at: int, available_at: int} $data */
        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        $newAttempts = $data['attempts'] + 1;

        $this->ackIds[$data['id']] = $ackId;
        $this->jobSubscriptions[$data['id']] = $subscriptionPath;

        $record = new JobRecord(
            id: $data['id'],
            queue: $data['queue'],
            jobClass: $data['job_class'],
            payload: $data['payload'],
            attempts: $newAttempts,
            status: JobRecordStatus::Processing,
            createdAt: $data['created_at'],
            availableAt: $data['available_at'],
        );

        $this->knownJobs[$data['id']] = $record;

        return $record;
    }

    #[Override]
    public function acknowledge(string $jobId): void
    {
        if (!isset($this->ackIds[$jobId], $this->jobSubscriptions[$jobId])) {
            return;
        }

        $url = sprintf('%s/%s:acknowledge', self::API_BASE, $this->jobSubscriptions[$jobId]);

        $requestBody = json_encode([
            'ackIds' => [$this->ackIds[$jobId]],
        ], JSON_THROW_ON_ERROR);

        $this->pubSubRequest('POST', $url, $requestBody);

        unset(
            $this->ackIds[$jobId],
            $this->jobSubscriptions[$jobId],
            $this->knownJobs[$jobId],
        );
    }

    #[Override]
    public function reject(string $jobId, string $reason): void
    {
        if (!isset($this->ackIds[$jobId], $this->jobSubscriptions[$jobId])) {
            return;
        }

        $this->modifyAckDeadline($this->jobSubscriptions[$jobId], $this->ackIds[$jobId], 0);

        if (isset($this->knownJobs[$jobId])) {
            $existing = $this->knownJobs[$jobId];

            $this->knownJobs[$jobId] = new JobRecord(
                id: $existing->id,
                queue: $existing->queue,
                jobClass: $existing->jobClass,
                payload: $existing->payload,
                attempts: $existing->attempts,
                status: JobRecordStatus::Failed,
                createdAt: $existing->createdAt,
                availableAt: $existing->availableAt,
            );
        }

        unset($this->ackIds[$jobId], $this->jobSubscriptions[$jobId]);
    }

    #[Override]
    public function size(string $queue): int
    {
        // Pub/Sub does not natively expose pending message count via REST.
        // Use Cloud Monitoring API for accurate counts in production.
        return 0;
    }

    #[Override]
    public function purge(string $queue): int
    {
        // Seek the subscription to "now" to discard all current messages
        $subscriptionPath = $this->subscriptionPath($queue);
        $url = sprintf('%s/%s:seek', self::API_BASE, $subscriptionPath);

        $requestBody = json_encode([
            'time' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
        ], JSON_THROW_ON_ERROR);

        $this->pubSubRequest('POST', $url, $requestBody);

        return 0;
    }

    /**
     * @return list<JobRecord>
     */
    #[Override]
    public function findByStatus(JobRecordStatus $status): array
    {
        $results = [];

        foreach ($this->knownJobs as $record) {
            if ($record->status === $status) {
                $results[] = $record;
            }
        }

        return $results;
    }

    private function pubSubRequest(string $method, string $url, string $body = ''): string
    {
        $headers = $this->authHeaders();
        $headers['Content-Type'] = 'application/json';

        try {
            $response = $this->httpClient->request($method, $url, $headers, $body);
        } catch (CloudException $e) {
            throw CloudException::requestFailed('pubsub', $e->getMessage(), $e);
        }

        if ($response->statusCode >= 400) {
            throw CloudException::requestFailed(
                'pubsub',
                sprintf('HTTP %d: %s', $response->statusCode, $response->body),
            );
        }

        return $response->body;
    }

    private function modifyAckDeadline(string $subscriptionPath, string $ackId, int $seconds): void
    {
        $url = sprintf('%s/%s:modifyAckDeadline', self::API_BASE, $subscriptionPath);

        $requestBody = json_encode([
            'ackIds' => [$ackId],
            'ackDeadlineSeconds' => $seconds,
        ], JSON_THROW_ON_ERROR);

        $this->pubSubRequest('POST', $url, $requestBody);
    }

    private function topicPath(string $queue): string
    {
        $projectId = $this->config->resolveProjectId();

        return sprintf('projects/%s/topics/%s%s', $projectId, $this->topicPrefix, $queue);
    }

    private function subscriptionPath(string $queue): string
    {
        $projectId = $this->config->resolveProjectId();

        return sprintf('projects/%s/subscriptions/%s%s', $projectId, $this->subscriptionPrefix, $queue);
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        $token = $this->config->resolveAccessToken();

        if ($token === '') {
            throw CloudException::authenticationFailed('gcp', 'GCP access token not configured for Pub/Sub');
        }

        return [
            'Authorization' => 'Bearer ' . $token,
        ];
    }
}
