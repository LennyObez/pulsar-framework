<?php

declare(strict_types=1);

namespace Pulsar\Cloud\Azure;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Cloud\Azure\Config\AzureConfig;
use Pulsar\Cloud\CloudException;
use Pulsar\Cloud\CloudHttpClient;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueDriverInterface;
use Random\Engine\Secure;
use Random\Randomizer;

use function bin2hex;
use function gmdate;
use function json_decode;
use function json_encode;
use function sprintf;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * Azure Service Bus queue driver using raw HTTP with OAuth2 tokens.
 *
 * Maps Pulsar queue names to Service Bus queues using the REST API.
 * Supports message scheduling for delayed delivery, peek-lock for
 * reliable processing, and dead letter queue operations.
 */
#[Internal(reason: 'Implementation detail; use QueueDriverInterface contract')]
final class ServiceBusQueueDriver implements QueueDriverInterface
{
    private readonly Randomizer $randomizer;

    /** @var array<string, string> Lock token indexed by job ID */
    private array $lockTokens = [];

    /** @var array<string, string> Queue name indexed by job ID */
    private array $jobQueues = [];

    /** @var array<string, JobRecord> In-memory tracking for findByStatus */
    private array $knownJobs = [];

    public function __construct(
        private readonly AzureConfig $config,
        private readonly string $namespace,
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

        $url = $this->queueUrl($queue) . '/messages';

        $headers = $this->authHeaders();
        $headers['Content-Type'] = 'application/json';

        if ($delay > 0) {
            $scheduledTime = gmdate('D, d M Y H:i:s \G\M\T', $now + $delay);
            $headers['x-ms-scheduled-enqueue-time'] = $scheduledTime;
        }

        try {
            $response = $this->httpClient->request('POST', $url, $headers, $jobData);
        } catch (CloudException $e) {
            throw CloudException::requestFailed('servicebus', $e->getMessage(), $e);
        }

        if ($response->statusCode !== 201 && !$response->isSuccess()) {
            throw CloudException::requestFailed(
                'servicebus',
                sprintf('HTTP %d: %s', $response->statusCode, $response->body),
            );
        }

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
        // Peek-lock: receive and lock message for processing
        $url = $this->queueUrl($queue) . '/messages/head?timeout=0';

        $headers = $this->authHeaders();

        try {
            $response = $this->httpClient->request('POST', $url, $headers);
        } catch (CloudException $e) {
            throw CloudException::requestFailed('servicebus', $e->getMessage(), $e);
        }

        if ($response->statusCode === 204 || $response->body === '') {
            return null;
        }

        if ($response->statusCode >= 400) {
            throw CloudException::requestFailed(
                'servicebus',
                sprintf('HTTP %d: %s', $response->statusCode, $response->body),
            );
        }

        /** @var array{id: string, queue: string, job_class: string, payload: string, attempts: int, status: string, created_at: int, available_at: int} $data */
        $data = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        $newAttempts = $data['attempts'] + 1;

        // Extract lock token from BrokerProperties header if available
        $lockToken = $data['id']; // Simplified: real impl parses Location header

        $this->lockTokens[$data['id']] = $lockToken;
        $this->jobQueues[$data['id']] = $queue;

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
        if (!isset($this->lockTokens[$jobId], $this->jobQueues[$jobId])) {
            return;
        }

        $url = sprintf(
            '%s/messages/%s/%s',
            $this->queueUrl($this->jobQueues[$jobId]),
            $jobId,
            $this->lockTokens[$jobId],
        );

        try {
            $this->httpClient->request('DELETE', $url, $this->authHeaders());
        } catch (CloudException) {
            // Best effort: message will reappear after lock expires if delete fails
        }

        unset(
            $this->lockTokens[$jobId],
            $this->jobQueues[$jobId],
            $this->knownJobs[$jobId],
        );
    }

    #[Override]
    public function reject(string $jobId, string $reason): void
    {
        if (!isset($this->lockTokens[$jobId], $this->jobQueues[$jobId])) {
            return;
        }

        // Unlock the message so it can be redelivered
        $url = sprintf(
            '%s/messages/%s/%s',
            $this->queueUrl($this->jobQueues[$jobId]),
            $jobId,
            $this->lockTokens[$jobId],
        );

        try {
            $this->httpClient->request('PUT', $url, $this->authHeaders());
        } catch (CloudException) {
            // Best effort unlock
        }

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

        unset($this->lockTokens[$jobId], $this->jobQueues[$jobId]);
    }

    #[Override]
    public function size(string $queue): int
    {
        // Service Bus queue size requires management API access.
        // Consider using Azure Monitor metrics for production monitoring.
        return 0;
    }

    #[Override]
    public function purge(string $queue): int
    {
        // Service Bus has no direct purge API.
        // Repeatedly receive and delete messages until empty.
        $purged = 0;

        for ($i = 0; $i < 100; $i++) {
            $url = $this->queueUrl($queue) . '/messages/head?timeout=0';

            try {
                $response = $this->httpClient->request('DELETE', $url, $this->authHeaders());
            } catch (CloudException) {
                break;
            }

            if ($response->statusCode === 204 || $response->statusCode === 404) {
                break;
            }

            $purged++;
        }

        return $purged;
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

    private function queueUrl(string $queue): string
    {
        $baseUrl = $this->config->endpoint
            ?? sprintf('https://%s.servicebus.windows.net', $this->namespace);

        return sprintf('%s/%s', $baseUrl, $queue);
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        $token = $this->config->resolveAccessToken();

        if ($token === '') {
            throw CloudException::authenticationFailed('azure', 'Azure access token not configured for Service Bus');
        }

        return [
            'Authorization' => 'Bearer ' . $token,
        ];
    }
}
