<?php

/**
 * Stub for the grpc PECL extension.
 *
 * Provides type declarations for Psalm static analysis.
 * The actual classes are provided by the grpc PHP extension at runtime.
 */

namespace Grpc;

class Server
{
    public function __construct(array $args = []) {}

    public function addHttp2Port(string $address, ServerCredentials $credentials): int
    {
        return 0;
    }

    public function start(): void {}

    /** @return object|null */
    public function requestCall(): ?object
    {
        return null;
    }

    public function shutdown(): void {}
}

class ServerCredentials
{
    /**
     * @param string|null $rootCerts
     * @param list<array{cert_chain: string, private_key: string}> $keyCertPairs
     * @param bool $forceClientAuth
     */
    public static function createSsl(?string $rootCerts, array $keyCertPairs, bool $forceClientAuth = false): self
    {
        return new self();
    }

    public static function createInsecure(): self
    {
        return new self();
    }
}

const OP_SEND_INITIAL_METADATA = 0;
const OP_SEND_MESSAGE = 1;
const OP_SEND_STATUS_FROM_SERVER = 2;
const OP_RECV_CLOSE_ON_SERVER = 4;
