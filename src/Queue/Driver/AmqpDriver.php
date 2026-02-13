<?php

declare(strict_types=1);

namespace Pulsar\Queue\Driver;

use AMQPChannel;
use AMQPConnection;
use AMQPEnvelope;
use AMQPExchange;
use AMQPQueue;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Queue\Driver\Config\AmqpDriverConfig;
use Pulsar\Queue\Exception\QueueException;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueDriverInterface;
use Random\Engine\Secure;
use Random\Randomizer;

use function bin2hex;
use function class_exists;
use function extension_loaded;
use function json_decode;
use function json_encode;
use function time;

use const AMQP_DURABLE;
use const AMQP_EX_TYPE_DIRECT;
use const AMQP_NOPARAM;
use const JSON_THROW_ON_ERROR;

/**
 * AMQP queue driver using the ext-amqp extension.
 *
 * Uses a direct exchange with queue names as routing keys. Delayed
 * messages are supported via message TTL and dead-letter exchange routing.
 * Delivery tags are tracked internally for acknowledge/reject operations.
 */
#[Internal(reason: 'Implementation detail — use QueueDriverInterface contract')]
final class AmqpDriver implements QueueDriverInterface
{
    private readonly Randomizer $randomizer;

    private ?AMQPConnection $connection = null;

    private ?AMQPChannel $channel = null;

    private ?AMQPExchange $exchange = null;

    /** @var array<string, AMQPQueue> */
    private array $queues = [];

    /** @var array<string, int> Delivery tag indexed by job ID */
    private array $deliveryTags = [];

    /** @var array<string, string> Queue name indexed by job ID */
    private array $jobQueues = [];

    /** @var array<string, JobRecord> In-memory tracking for findByStatus */
    private array $knownJobs = [];

    public function __construct(
        private readonly AmqpDriverConfig $config,
        ?Randomizer $randomizer = null,
    ) {
        if (!extension_loaded('amqp')) {
            throw QueueException::driverNotConfigured(
                'amqp (ext-amqp is not installed)',
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

        $exchange = $this->exchange();
        $this->declareQueue($queue);

        /** @var array<string, mixed> $attributes */
        $attributes = [
            'delivery_mode' => 2,
            'content_type' => 'application/json',
            'message_id' => $id,
        ];

        if ($delay > 0) {
            $this->declareDelayQueue($queue, $delay);
            $exchange->publish(
                $jobData,
                $queue . '.delay.' . $delay,
                AMQP_NOPARAM,
                $attributes,
            );
        } else {
            $exchange->publish(
                $jobData,
                $queue,
                AMQP_NOPARAM,
                $attributes,
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
        $amqpQueue = $this->declareQueue($queue);

        $envelope = $amqpQueue->get(AMQP_NOPARAM);

        if (!$envelope instanceof AMQPEnvelope) {
            return null;
        }

        $body = $envelope->getBody();

        /** @var array{id: string, queue: string, job_class: string, payload: string, attempts: int, status: string, created_at: int, available_at: int} $data */
        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        $newAttempts = $data['attempts'] + 1;

        $this->deliveryTags[$data['id']] = $envelope->getDeliveryTag() ?? 0;
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
        if (!isset($this->deliveryTags[$jobId], $this->jobQueues[$jobId])) {
            return;
        }

        $queue = $this->declareQueue($this->jobQueues[$jobId]);
        $queue->ack($this->deliveryTags[$jobId]);

        unset($this->deliveryTags[$jobId], $this->jobQueues[$jobId], $this->knownJobs[$jobId]);
    }

    #[Override]
    public function reject(string $jobId, string $reason): void
    {
        if (!isset($this->deliveryTags[$jobId], $this->jobQueues[$jobId])) {
            return;
        }

        $queue = $this->declareQueue($this->jobQueues[$jobId]);
        $queue->nack($this->deliveryTags[$jobId], AMQP_NOPARAM);

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

        unset($this->deliveryTags[$jobId], $this->jobQueues[$jobId]);
    }

    #[Override]
    public function size(string $queue): int
    {
        $amqpQueue = $this->declareQueue($queue);

        return $amqpQueue->declareQueue();
    }

    #[Override]
    public function purge(string $queue): int
    {
        $amqpQueue = $this->declareQueue($queue);
        $count = $amqpQueue->declareQueue();

        $amqpQueue->purge();

        return $count;
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

    private function connect(): AMQPConnection
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        if (!class_exists(AMQPConnection::class)) {
            throw QueueException::driverNotConfigured(
                'amqp (ext-amqp is not installed)',
            );
        }

        $this->connection = new AMQPConnection();
        $this->connection->setHost($this->config->host);
        $this->connection->setPort($this->config->port);
        $this->connection->setLogin($this->config->user);
        $this->connection->setPassword($this->config->password);
        $this->connection->setVhost($this->config->vhost);
        $this->connection->connect();

        return $this->connection;
    }

    private function channel(): AMQPChannel
    {
        if ($this->channel !== null) {
            return $this->channel;
        }

        $this->channel = new AMQPChannel($this->connect());

        return $this->channel;
    }

    private function exchange(): AMQPExchange
    {
        if ($this->exchange !== null) {
            return $this->exchange;
        }

        $this->exchange = new AMQPExchange($this->channel());
        $this->exchange->setName($this->config->exchange);
        $this->exchange->setType(AMQP_EX_TYPE_DIRECT);
        $this->exchange->setFlags(AMQP_DURABLE);
        $this->exchange->declareExchange();

        return $this->exchange;
    }

    private function declareQueue(string $name): AMQPQueue
    {
        if (isset($this->queues[$name])) {
            return $this->queues[$name];
        }

        $queue = new AMQPQueue($this->channel());
        $queue->setName($name);
        $queue->setFlags(AMQP_DURABLE);
        $queue->declareQueue();
        $queue->bind($this->config->exchange, $name);

        $this->queues[$name] = $queue;

        return $queue;
    }

    private function declareDelayQueue(string $targetQueue, int $delay): void
    {
        $delayName = $targetQueue . '.delay.' . $delay;

        if (isset($this->queues[$delayName])) {
            return;
        }

        $queue = new AMQPQueue($this->channel());
        $queue->setName($delayName);
        $queue->setFlags(AMQP_DURABLE);
        $queue->setArgument('x-message-ttl', $delay * 1000);
        $queue->setArgument('x-dead-letter-exchange', $this->config->exchange);
        $queue->setArgument('x-dead-letter-routing-key', $targetQueue);
        $queue->declareQueue();

        $this->exchange();
        $queue->bind($this->config->exchange, $delayName);

        $this->queues[$delayName] = $queue;
    }
}
