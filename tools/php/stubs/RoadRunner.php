<?php

/**
 * Stub for Spiral RoadRunner.
 *
 * Provides type declarations for Psalm static analysis.
 * The actual classes are provided by the spiral/roadrunner package at runtime.
 */

namespace Spiral\RoadRunner;

class Worker
{
    public static function create(): self
    {
        return new self();
    }

    /** @return ?Payload */
    public function waitPayload(): ?Payload
    {
        return null;
    }

    public function respond(Payload $payload): void {}

    public function error(string $message): void {}
}

class Payload
{
    public string $body = '';
    public string $header = '';

    public function __construct(string $body = '', string $header = '') {
        $this->body = $body;
        $this->header = $header;
    }
}
