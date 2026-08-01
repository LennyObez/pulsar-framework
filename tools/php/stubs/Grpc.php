<?php

namespace Grpc;

/**
 * The ext-grpc surface Pulsar uses, transcribed from the extension's own C source.
 *
 * This file replaces jetbrains/phpstorm-stubs' grpc stub rather than supplementing
 * it, which is the opposite of the rule everywhere else in this directory — upstream
 * is normally the authority precisely because a hand-written copy drifts. Two of its
 * gRPC declarations are provably wrong, and believing them means editing correct code
 * to match an incorrect description:
 *
 *   - `Server::requestCall($tag_new, $tag_cancel)` — upstream gives it two
 *     completion-queue tags, apparently transcribed from the C-core function
 *     `grpc_server_request_call()`. The PHP method registers none:
 *         ZEND_BEGIN_ARG_INFO_EX(arginfo_requestCall, 0, 0, 0)
 *         ZEND_END_ARG_INFO()
 *     — src/php/ext/grpc/server.c. Following upstream would have meant passing two
 *     arguments the extension rejects, breaking an accept loop that is correct.
 *
 *   - `ServerCredentials::createSsl(string $pem_root_certs = null, ...)` — a
 *     non-nullable type with a null default, so analysers read the parameter as
 *     `string`. The extension parses `"s!ss"`, where `s!` accepts null, and null is
 *     the meaningful value for "no client CA":
 *         zend_parse_parameters(ZEND_NUM_ARGS(), "s!ss", &pem_root_certs, ...)
 *     — src/php/ext/grpc/server_credentials.c.
 *
 * Scope is deliberately the four symbols the framework touches and nothing else:
 * a stub that describes less has less to drift. `Grpc\Call` is absent on purpose —
 * GrpcExtensionAdapter reaches startBatch() through method_exists() on an `object`,
 * so no declaration is needed or wanted.
 *
 * Registered in AnalyzerStubsTest::UPSTREAM_CORRECTIONS, which asserts the upstream
 * file it replaces is not also loaded, so the two can never both be in play. Retire
 * this file when upstream fixes the arity — the guard will say so.
 *
 * Analysis-only, never loaded at runtime.
 */
class Server
{
    /**
     * @param array<string, mixed> $args
     */
    public function __construct(array $args = []) {}

    /**
     * ZEND_BEGIN_ARG_INFO_EX(arginfo_addHttp2Port, 0, 0, 1) — one required address.
     *
     * @return int The bound port, or 0 when the bind failed
     */
    public function addHttp2Port(string $addr): int
    {
        return 0;
    }

    /**
     * ZEND_BEGIN_ARG_INFO_EX(arginfo_addSecureHttp2Port, 0, 0, 2) — address + creds.
     *
     * @return int The bound port, or 0 when the bind failed
     */
    public function addSecureHttp2Port(string $addr, ServerCredentials $serverCreds): int
    {
        return 0;
    }

    /**
     * ZEND_BEGIN_ARG_INFO_EX(arginfo_requestCall, 0, 0, 0) — no parameters.
     *
     * @return object|null An event carrying method, host, call, absolute_deadline
     *                     and metadata
     */
    public function requestCall(): ?object
    {
        return null;
    }

    /**
     * ZEND_BEGIN_ARG_INFO_EX(arginfo_start, 0, 0, 0).
     */
    public function start(): void {}
}

class ServerCredentials
{
    /**
     * Parsed as `"s!ss"`: nullable client-CA bundle, private key, certificate chain.
     *
     * The body passes GRPC_SSL_DONT_REQUEST_CLIENT_CERTIFICATE to
     * grpc_ssl_server_credentials_create_ex() unconditionally, so no CA bundle here
     * can produce mutual TLS. GrpcExtensionAdapter refuses one for that reason.
     */
    public static function createSsl(?string $pemRootCerts, string $pemPrivateKey, string $pemCertChain): self
    {
        return new self();
    }
}

const OP_SEND_INITIAL_METADATA = 0;
const OP_SEND_MESSAGE = 1;
const OP_SEND_STATUS_FROM_SERVER = 3;
const OP_RECV_CLOSE_ON_SERVER = 5;
