<?php

declare(strict_types=1);

namespace Pulsar\Queue\Driver;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Queue\Driver\Config\RedisDriverConfig;
use Pulsar\Queue\Exception\QueueException;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Support\RedisReply;
use Random\Engine\Secure;
use Random\Randomizer;
use Redis;

use function bin2hex;
use function class_exists;
use function extension_loaded;
use function json_decode;
use function json_encode;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * Redis-backed queue driver using the reliable queue pattern.
 *
 * Ready jobs are stored in a Redis list. Delayed jobs use a sorted set
 * with the availableAt timestamp as score. Pop operations atomically
 * move jobs from the ready list to a processing list via RPOPLPUSH.
 */
#[Internal(reason: 'Implementation detail; use QueueDriverInterface contract')]
final class RedisDriver implements QueueDriverInterface
{
    private readonly Randomizer $randomizer;

    private ?Redis $redis = null;

    public function __construct(
        private readonly RedisDriverConfig $config,
        ?Randomizer $randomizer = null,
    ) {
        if (!extension_loaded('redis')) {
            throw QueueException::driverNotConfigured(
                'redis (ext-redis is not installed)',
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

        $redis = $this->connection();

        if ($delay > 0) {
            $redis->zAdd(
                $this->key($queue, 'delayed'),
                $now + $delay,
                $jobData,
            );
        } else {
            $redis->rPush($this->key($queue), $jobData);
        }

        $redis->hSet($this->key($queue, 'meta'), $id, $jobData);

        return $id;
    }

    #[Override]
    public function pop(string $queue): ?JobRecord
    {
        $redis = $this->connection();
        $now = time();

        $this->migrateDelayedJobs($redis, $queue, $now);

        $jobData = RedisReply::stringOrFalse($redis->rPopLPush(
            $this->key($queue),
            $this->key($queue, 'processing'),
        ));

        if ($jobData === false) {
            return null;
        }

        /** @var array{id: string, queue: string, job_class: string, payload: string, attempts: int, status: string, created_at: int, available_at: int} $data */
        $data = json_decode($jobData, true, flags: JSON_THROW_ON_ERROR);

        $newAttempts = $data['attempts'] + 1;

        $updatedData = json_encode([
            'id' => $data['id'],
            'queue' => $data['queue'],
            'job_class' => $data['job_class'],
            'payload' => $data['payload'],
            'attempts' => $newAttempts,
            'status' => JobRecordStatus::Processing->value,
            'created_at' => $data['created_at'],
            'available_at' => $data['available_at'],
        ], JSON_THROW_ON_ERROR);

        $redis->hSet($this->key($queue, 'meta'), $data['id'], $updatedData);

        return new JobRecord(
            id: $data['id'],
            queue: $data['queue'],
            jobClass: $data['job_class'],
            payload: $data['payload'],
            attempts: $newAttempts,
            status: JobRecordStatus::Processing,
            createdAt: $data['created_at'],
            availableAt: $data['available_at'],
        );
    }

    #[Override]
    public function acknowledge(string $jobId): void
    {
        $redis = $this->connection();

        $this->removeFromProcessingById($redis, $jobId);

        $allQueues = RedisReply::strings($redis->sMembers($this->config->prefix . 'queues'));

        foreach ($allQueues as $queueName) {
            $redis->hDel($this->key($queueName, 'meta'), $jobId);
        }
    }

    #[Override]
    public function reject(string $jobId, string $reason): void
    {
        $redis = $this->connection();

        $this->removeFromProcessingById($redis, $jobId);

        $allQueues = RedisReply::strings($redis->sMembers($this->config->prefix . 'queues'));

        foreach ($allQueues as $queueName) {
            $metaKey = $this->key($queueName, 'meta');
            $raw = RedisReply::stringOrFalse($redis->hGet($metaKey, $jobId));

            if ($raw === false) {
                continue;
            }

            /** @var array{id: string, queue: string, job_class: string, payload: string, attempts: int, status: string, created_at: int, available_at: int} $data */
            $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

            $data['status'] = JobRecordStatus::Failed->value;

            $redis->hSet($metaKey, $jobId, json_encode($data, JSON_THROW_ON_ERROR));
            $redis->hSet($this->key($queueName, 'failed'), $jobId, $reason);

            return;
        }
    }

    #[Override]
    public function size(string $queue): int
    {
        $redis = $this->connection();

        return RedisReply::count($redis->lLen($this->key($queue)));
    }

    #[Override]
    public function purge(string $queue): int
    {
        $redis = $this->connection();

        $size = RedisReply::count($redis->lLen($this->key($queue)));
        $delayed = RedisReply::count($redis->zCard($this->key($queue, 'delayed')));
        $total = $size + $delayed;

        $redis->del(
            $this->key($queue),
            $this->key($queue, 'delayed'),
            $this->key($queue, 'processing'),
            $this->key($queue, 'meta'),
            $this->key($queue, 'failed'),
        );

        $redis->sRem($this->config->prefix . 'queues', $queue);

        return $total;
    }

    /**
     * @return list<JobRecord>
     */
    #[Override]
    public function findByStatus(JobRecordStatus $status): array
    {
        $redis = $this->connection();

        $allQueues = RedisReply::strings($redis->sMembers($this->config->prefix . 'queues'));
        $records = [];

        foreach ($allQueues as $queueName) {
            $allMeta = RedisReply::strings($redis->hGetAll($this->key($queueName, 'meta')));

            foreach ($allMeta as $encoded) {
                /** @var array{id: string, queue: string, job_class: string, payload: string, attempts: int, status: string, created_at: int, available_at: int} $data */
                $data = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);

                if ($data['status'] === $status->value) {
                    $records[] = new JobRecord(
                        id: $data['id'],
                        queue: $data['queue'],
                        jobClass: $data['job_class'],
                        payload: $data['payload'],
                        attempts: $data['attempts'],
                        status: JobRecordStatus::from($data['status']),
                        createdAt: $data['created_at'],
                        availableAt: $data['available_at'],
                    );
                }
            }
        }

        return $records;
    }

    private function connection(): Redis
    {
        if ($this->redis !== null) {
            return $this->redis;
        }

        if (!class_exists(Redis::class)) {
            throw QueueException::driverNotConfigured(
                'redis (ext-redis is not installed)',
            );
        }

        $this->redis = new Redis();

        $this->redis->connect(
            $this->config->host,
            $this->config->port,
            $this->config->timeout,
        );

        if ($this->config->password !== '') {
            $this->redis->auth($this->config->password);
        }

        if ($this->config->database !== 0) {
            $this->redis->select($this->config->database);
        }

        return $this->redis;
    }

    private function key(string $queue, string $suffix = ''): string
    {
        $redis = $this->connection();

        $redis->sAdd($this->config->prefix . 'queues', $queue);

        $key = $this->config->prefix . $queue;

        if ($suffix !== '') {
            $key .= ':' . $suffix;
        }

        return $key;
    }

    /**
     * Lua script for atomic delayed job migration.
     *
     * Atomically moves all jobs whose score <= now from the delayed sorted set
     * to the ready list, preventing race conditions where multiple workers could
     * migrate the same job simultaneously.
     *
     * Uses Redis server-side Lua (EVAL) to guarantee atomicity: this is NOT
     * JavaScript eval() and poses no code injection risk (the script is a
     * compile-time constant, not user input).
     */
    private const string MIGRATE_LUA = <<<'LUA'
        local delayed_key = KEYS[1]
        local queue_key = KEYS[2]
        local now = ARGV[1]
        local jobs = redis.call('ZRANGEBYSCORE', delayed_key, '-inf', now)
        if #jobs == 0 then
            return 0
        end
        for i, job in ipairs(jobs) do
            redis.call('ZREM', delayed_key, job)
            redis.call('RPUSH', queue_key, job)
        end
        return #jobs
        LUA;

    private function migrateDelayedJobs(Redis $redis, string $queue, int $now): void
    {
        $delayedKey = $this->key($queue, 'delayed');
        $queueKey = $this->key($queue);

        $redis->eval(self::MIGRATE_LUA, [$delayedKey, $queueKey, (string) $now], 2);
    }

    private function removeFromProcessingById(Redis $redis, string $jobId): void
    {
        $allQueues = RedisReply::strings($redis->sMembers($this->config->prefix . 'queues'));

        foreach ($allQueues as $queueName) {
            $processingKey = $this->key($queueName, 'processing');

            /** @var list<string> $items */
            $items = $redis->lRange($processingKey, 0, -1);

            foreach ($items as $item) {
                /** @var array{id: string} $data */
                $data = json_decode($item, true, flags: JSON_THROW_ON_ERROR);

                if ($data['id'] === $jobId) {
                    $redis->lRem($processingKey, $item, 1);

                    return;
                }
            }
        }
    }
}
