<?php

declare(strict_types=1);

/**
 * Benchmark Pipeline Manifest — Canonical contract for performance benchmarks.
 *
 * This file declares the exact middleware stacks, storage backends, authentication
 * requirements, expected outputs, and required side-effects for each request-class
 * benchmark. The benchmark runner validates this manifest against semantic contracts
 * before executing benchmarks.
 *
 * GOVERNANCE:
 * - Changes to this file require Performance Engineer sign-off.
 * - Changes that reduce security/compliance coverage also require Architecture sign-off.
 * - This file is content-hashed in CI; unauthorized changes fail the build.
 *
 * @see docs/performance.md
 */

return [
    'version' => '1.0.0',

    // ──────────────────────────────────────────────────────────────
    // Storage backends per tier
    // ──────────────────────────────────────────────────────────────
    'storage' => [
        'tier_a' => [
            'session' => 'Pulsar\Security\Session\Handler\ArrayHandler',
            'audit_sink' => 'Pulsar\Tests\Benchmark\Support\InMemoryAuditSink',
            'cache' => 'Pulsar\Tests\Benchmark\Support\NullCache',
            'compliance_dispatcher' => 'Pulsar\Tests\Benchmark\Support\NullComplianceDispatcher',
            'token_resolver' => 'Pulsar\Tests\Benchmark\Support\StubTokenResolver',
        ],
        'tier_b' => [
            'session' => 'Pulsar\Security\Session\Handler\RedisHandler',
            'audit_sink' => 'Pulsar\Security\Audit\AuditFileSink',
            'cache' => null, // Real Redis cache — configured at runtime
            'compliance_dispatcher' => null, // Real dispatcher — configured at runtime
            'token_resolver' => null, // Real token resolver — configured at runtime
        ],
    ],

    // ──────────────────────────────────────────────────────────────
    // Request-class semantic contracts
    // ──────────────────────────────────────────────────────────────
    'request_classes' => [
        'request.anonymous_json_api' => [
            'middleware' => [
                'error-handler',
                'routing',
                'content-negotiation',
            ],
            'auth' => null,
            'required_outputs' => ['json_serialization'],
            'required_side_effects' => [],
        ],

        'request.authenticated_session' => [
            'middleware' => [
                'error-handler',
                'routing',
                'session-start',
                'auth-guard',
                'authorization',
                'content-negotiation',
            ],
            'auth' => 'session',
            'required_outputs' => ['json_serialization'],
            'required_side_effects' => ['session_read_write'],
        ],

        'request.authenticated_token' => [
            'middleware' => [
                'error-handler',
                'routing',
                'token-resolver',
                'auth-guard',
                'authorization',
                'content-negotiation',
            ],
            'auth' => 'bearer_token',
            'required_outputs' => ['json_serialization'],
            'required_side_effects' => ['token_validation'],
        ],

        'request.with_audit' => [
            'middleware' => [
                'error-handler',
                'routing',
                'session-start',
                'auth-guard',
                'authorization',
                'audit-writer',
                'content-negotiation',
            ],
            'auth' => 'session',
            'required_outputs' => ['json_serialization'],
            'required_side_effects' => ['session_read_write', 'audit_log_write_hmac'],
        ],

        'request.compliance_event' => [
            'middleware' => [
                'error-handler',
                'routing',
                'session-start',
                'auth-guard',
                'authorization',
                'audit-writer',
                'compliance-dispatcher',
                'content-negotiation',
            ],
            'auth' => 'session',
            'required_outputs' => ['json_serialization'],
            'required_side_effects' => ['session_read_write', 'audit_log_write_hmac', 'compliance_event_dispatch'],
        ],
    ],

    // ──────────────────────────────────────────────────────────────
    // Middleware ID → class mapping
    // ──────────────────────────────────────────────────────────────
    'middleware_map' => [
        'error-handler' => 'Pulsar\Tests\Benchmark\Support\BenchErrorHandlerMiddleware',
        'routing' => 'Pulsar\Tests\Benchmark\Support\BenchRoutingMiddleware',
        'session-start' => 'Pulsar\Security\Session\SessionMiddleware',
        'auth-guard' => 'Pulsar\Auth\Middleware\AuthenticationMiddleware',
        'authorization' => 'Pulsar\Auth\Middleware\AuthorizationMiddleware',
        'token-resolver' => 'Pulsar\Tests\Benchmark\Support\BenchTokenResolverMiddleware',
        'audit-writer' => 'Pulsar\Tests\Benchmark\Support\BenchAuditWriterMiddleware',
        'compliance-dispatcher' => 'Pulsar\Tests\Benchmark\Support\BenchComplianceDispatcherMiddleware',
        'content-negotiation' => 'Pulsar\Tests\Benchmark\Support\BenchContentNegotiationMiddleware',
    ],

    // ──────────────────────────────────────────────────────────────
    // Crypto benchmark parameters (locked to approved algorithms)
    // ──────────────────────────────────────────────────────────────
    'crypto' => [
        'aead_algorithm' => 'xchacha20-poly1305',
        'aead_payload_size' => 1024,
        'aead_aad' => 'benchmark|test|payload|1|corr-bench-001|1',
        'hmac_algorithm' => 'blake2b',
        'kdf_algorithm' => 'argon2id',
    ],

    // ──────────────────────────────────────────────────────────────
    // Statistical assertion configuration
    // ──────────────────────────────────────────────────────────────
    'statistics' => [
        'tier_a' => [
            'warmup_iterations' => 5,
            'measured_iterations' => 50,
            'assertion_metric' => 'p50',
            'variance_tolerance' => 0.05,
        ],
        'tier_b' => [
            'warmup_iterations' => 5,
            'measured_iterations' => 50,
            'assertion_metric' => 'p90',
            'variance_tolerance' => 0.15,
        ],
        'tier_c' => [
            'warmup_iterations' => 5,
            'measured_iterations' => 50,
            'assertion_metric' => 'p90',
            'variance_tolerance' => 0.10,
        ],
    ],
];
