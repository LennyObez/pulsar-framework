<?php

declare(strict_types=1);

namespace Pulsar\Queue\Driver;

use DateTimeImmutable;
use Google\Cloud\PubSub\Message;
use Google\Cloud\PubSub\PubSubClient;
use Google\Cloud\PubSub\Subscription;
use Google\Cloud\PubSub\Topic;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Queue\Driver\Config\PubSubDriverConfig;
use Pulsar\Queue\Exception\QueueException;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueDriverInterface;
use Random\Engine\Secure;
use Random\Randomizer;

use function bin2hex;
use function class_exists;
use function json_decode;
use function json_encode;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * Google Cloud Pub/Sub queue driver.
 *
 * Maps Pulsar queue names to Pub/Sub topics and subscriptions using
 * configurable prefixes. Delayed jobs store the availableAt timestamp
 * in message attributes and are nacked if not yet ready.
 */
#[Internal(reason: 'Implementation detail — use QueueDriverInterface contract')]
final class PubSubDriver implements QueueDriverInterface
{
    private readonly Randomizer $randomizer;

    private ?PubSubClient $client = null;

    /** @var array<string, Topic> */
    private array $topics = [];

    /** @var array<string, Subscription> */
    private array $subscriptions = [];

    /** @var array<string, Message> Message indexed by job ID for ack/nack */
    private array $pendingMessages = [];

    /** @var array<string, string> Subscription name indexed by job ID */
    private array $jobSubscriptions = [];

    /** @var array<string, JobRecord> In-memory tracking for findByStatus */
    private array $knownJobs = [];

    public function __construct(
        private readonly PubSubDriverConfig $config,
        ?Randomizer $randomizer = null,
    ) {
        if (!class_exists(PubSubClient::class)) {
            throw QueueException::driverNotConfigured(
                'pubsub (google/cloud-pubsub is not installed)',
            );
        }

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

        $topic = $this->topic($queue);

        $topic->publish([
            'data' => $jobData,
            'attributes' => [
                'job_id' => $id,
                'available_at' => (string) ($now + $delay),
            ],
        ]);

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
        $subscription = $this->subscription($queue);

        $messages = $subscription->pull([
            'maxMessages' => 1,
            'returnImmediately' => true,
        ]);

        if ($messages === []) {
            return null;
        }

        $message = $messages[0];
        $attributes = $message->attributes();
        $availableAt = (int) ($attributes['available_at'] ?? 0);
        $now = time();

        if ($availableAt > $now) {
            $subscription->modifyAckDeadline($message, 0);

            return null;
        }

        $body = $message->data();

        /** @var array{id: string, queue: string, job_class: string, payload: string, attempts: int, status: string, created_at: int, available_at: int} $data */
        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        $newAttempts = $data['attempts'] + 1;
        $subscriptionName = $this->subscriptionName($queue);

        $this->pendingMessages[$data['id']] = $message;
        $this->jobSubscriptions[$data['id']] = $subscriptionName;

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
        if (!isset($this->pendingMessages[$jobId], $this->jobSubscriptions[$jobId])) {
            return;
        }

        $subscriptionName = $this->jobSubscriptions[$jobId];
        $subscription = $this->subscriptions[$subscriptionName] ?? null;

        $subscription?->acknowledge($this->pendingMessages[$jobId]);

        unset(
            $this->pendingMessages[$jobId],
            $this->jobSubscriptions[$jobId],
            $this->knownJobs[$jobId],
        );
    }

    #[Override]
    public function reject(string $jobId, string $reason): void
    {
        if (!isset($this->pendingMessages[$jobId], $this->jobSubscriptions[$jobId])) {
            return;
        }

        $subscriptionName = $this->jobSubscriptions[$jobId];
        $subscription = $this->subscriptions[$subscriptionName] ?? null;

        $subscription?->modifyAckDeadline($this->pendingMessages[$jobId], 0);

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

        unset($this->pendingMessages[$jobId], $this->jobSubscriptions[$jobId]);
    }

    #[Override]
    public function size(string $queue): int
    {
        // Pub/Sub does not natively expose pending message count.
        // Use Cloud Monitoring API for accurate counts in production.
        return 0;
    }

    #[Override]
    public function purge(string $queue): int
    {
        $subscription = $this->subscription($queue);

        // Seeking to a future timestamp effectively discards all current messages.
        $subscription->seek([
            'time' => new DateTimeImmutable('+1 hour'),
        ]);

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

    private function pubSubClient(): PubSubClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        /** @var array<string, mixed> $config */
        $config = [];

        if ($this->config->projectId !== '') {
            $config['projectId'] = $this->config->projectId;
        }

        if ($this->config->keyFilePath !== '') {
            $config['keyFilePath'] = $this->config->keyFilePath;
        }

        $this->client = new PubSubClient($config);

        return $this->client;
    }

    private function topicName(string $queue): string
    {
        return $this->config->topicPrefix . $queue;
    }

    private function subscriptionName(string $queue): string
    {
        return $this->config->subscriptionPrefix . $queue;
    }

    private function topic(string $queue): Topic
    {
        $name = $this->topicName($queue);

        if (isset($this->topics[$name])) {
            return $this->topics[$name];
        }

        $client = $this->pubSubClient();
        $topic = $client->topic($name);

        if (!$topic->exists()) {
            $topic->create();
        }

        $this->topics[$name] = $topic;

        return $topic;
    }

    private function subscription(string $queue): Subscription
    {
        $name = $this->subscriptionName($queue);

        if (isset($this->subscriptions[$name])) {
            return $this->subscriptions[$name];
        }

        $topic = $this->topic($queue);
        $client = $this->pubSubClient();
        $subscription = $client->subscription($name, $topic->name());

        if (!$subscription->exists()) {
            $subscription->create();
        }

        $this->subscriptions[$name] = $subscription;

        return $subscription;
    }
}
