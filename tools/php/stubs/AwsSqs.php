<?php

/**
 * Minimal AWS SQS stub for PHPStan and Psalm static analysis.
 *
 * aws/aws-sdk-php is optional (listed in composer.json suggest).
 * This stub provides just enough type information for static
 * analyzers to analyze SqsDriver.
 */

namespace Aws\Sqs;

use Aws\Result;

class SqsClient
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = []) {}

    /**
     * @param array<string, mixed> $args
     * @return Result
     */
    public function sendMessage(array $args = []): Result {}

    /**
     * @param array<string, mixed> $args
     * @return Result
     */
    public function receiveMessage(array $args = []): Result {}

    /**
     * @param array<string, mixed> $args
     * @return Result
     */
    public function deleteMessage(array $args = []): Result {}

    /**
     * @param array<string, mixed> $args
     * @return Result
     */
    public function changeMessageVisibility(array $args = []): Result {}

    /**
     * @param array<string, mixed> $args
     * @return Result
     */
    public function getQueueAttributes(array $args = []): Result {}

    /**
     * @param array<string, mixed> $args
     * @return Result
     */
    public function purgeQueue(array $args = []): Result {}
}

namespace Aws;

/**
 * @implements \ArrayAccess<string, mixed>
 */
class Result implements \ArrayAccess
{
    /**
     * @param string $key
     * @return mixed
     */
    public function get(string $key): mixed {}

    /**
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists(mixed $offset): bool {}

    /**
     * @param mixed $offset
     * @return mixed
     */
    public function offsetGet(mixed $offset): mixed {}

    /**
     * @param mixed $offset
     * @param mixed $value
     */
    public function offsetSet(mixed $offset, mixed $value): void {}

    /**
     * @param mixed $offset
     */
    public function offsetUnset(mixed $offset): void {}
}
