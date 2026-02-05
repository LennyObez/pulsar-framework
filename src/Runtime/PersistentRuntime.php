<?php

declare(strict_types=1);

namespace Pulsar\Runtime;

use Override;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Config\RuntimeConfig;
use Pulsar\Core\KernelInterface;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Runtime\Exception\RuntimeException;
use Pulsar\Runtime\Fiber\FiberScheduler;
use Pulsar\Runtime\Http\ConnectionContext;
use Pulsar\Runtime\Http\HttpRequestParser;
use Pulsar\Runtime\Http\HttpResponseSerializer;
use Pulsar\Runtime\Upgrade\UpgradeContext;
use Pulsar\Runtime\Upgrade\UpgradeResponse;
use Socket;
use Throwable;

use function extension_loaded;
use function function_exists;
use function in_array;
use function memory_get_usage;
use function microtime;
use function register_shutdown_function;
use function socket_accept;
use function socket_bind;
use function socket_close;
use function socket_create;
use function socket_last_error;
use function socket_listen;
use function socket_read;
use function socket_select;
use function socket_set_option;
use function socket_strerror;
use function socket_write;
use function sprintf;
use function strlen;
use function time;

use const AF_INET;
use const SO_RCVTIMEO;
use const SO_REUSEADDR;
use const SOCK_STREAM;
use const SOL_SOCKET;
use const SOL_TCP;

/**
 * Long-running HTTP/1.1 server using ext-sockets.
 *
 * Boots the kernel once and handles many requests with strict
 * per-request isolation via RequestSandbox.
 */
#[Internal]
final class PersistentRuntime implements RuntimeInterface
{
    private RuntimeStatus $status = RuntimeStatus::Stopped;
    private int $requestCount = 0;
    private int $startedAt = 0;
    private ?Socket $serverSocket = null;

    private readonly HttpRequestParser $parser;
    private readonly HttpResponseSerializer $serializer;
    private ?FiberScheduler $scheduler = null;

    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly RequestSandbox $sandbox,
        private readonly RuntimeConfig $config,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?RuntimeCollectorInterface $collector = null,
        private readonly ?UpgradeContext $upgradeContext = null,
    ) {
        if (!extension_loaded('sockets')) {
            throw RuntimeException::extensionMissing('sockets');
        }

        $this->parser = new HttpRequestParser();
        $this->serializer = new HttpResponseSerializer();
    }

    /**
     * @throws RuntimeException If the server socket cannot be created or bound
     * @throws Throwable If the kernel fails to boot
     */
    #[Override]
    public function start(): void
    {
        $this->status = RuntimeStatus::Starting;
        $this->startedAt = time();
        $this->requestCount = 0;

        $this->registerFatalErrorHandler();
        $this->registerSignalHandlers();

        $this->kernel->boot();

        $this->serverSocket = $this->createServerSocket();

        $this->logger?->info(sprintf(
            'Pulsar runtime listening on %s:%d',
            $this->config->host,
            $this->config->port,
        ));

        $this->collector?->emitWorkerStart(
            host: $this->config->host,
            port: $this->config->port,
            fiberConcurrency: $this->config->fiberConcurrency,
            maxRequests: $this->config->maxRequests,
            memoryThresholdMb: $this->config->memoryThresholdMb,
        );

        $this->status = RuntimeStatus::Running;

        if ($this->config->fiberConcurrency > 0) {
            $this->scheduler = new FiberScheduler($this->config->fiberConcurrency);
            $this->runWithFibers();
        } else {
            $this->runSynchronous();
        }

        $this->shutdownGracefully();
    }

    #[Override]
    public function stop(): void
    {
        $this->status = RuntimeStatus::Stopping;
    }

    #[Override]
    public function beforeRequest(Request $request): Request
    {
        return $this->sandbox->beforeRequest($request);
    }

    #[Override]
    public function afterRequest(Request $request, Response $response): void
    {
        $this->sandbox->afterRequest($request, $response);
    }

    public function status(): RuntimeStatus
    {
        return $this->status;
    }

    public function requestCount(): int
    {
        return $this->requestCount;
    }

    /**
     * Synchronous accept loop — one connection at a time.
     *
     * @codeCoverageIgnore Requires a running server socket and real network connections
     */
    private function runSynchronous(): void
    {
        while ($this->status === RuntimeStatus::Running) {
            $this->checkSignals();

            if ($this->shouldRecycle()) {
                break;
            }

            $read = [$this->serverSocket];
            $write = [];
            $except = [];

            /** @var list<Socket> $read */
            $ready = @socket_select($read, $write, $except, 0, 100_000);

            if ($ready === false || $ready === 0) {
                continue;
            }

            /** @var Socket $serverSocket */
            $serverSocket = $this->serverSocket;
            $clientSocket = @socket_accept($serverSocket);

            if ($clientSocket === false) {
                continue;
            }

            $this->handleConnection($clientSocket);
        }
    }

    /**
     * Fiber-based concurrent accept loop.
     *
     * @codeCoverageIgnore Requires a running server socket and real network connections
     */
    private function runWithFibers(): void
    {
        /** @var FiberScheduler $scheduler */
        $scheduler = $this->scheduler;

        while ($this->status === RuntimeStatus::Running) {
            $this->checkSignals();

            if ($this->shouldRecycle()) {
                break;
            }

            // Accept new connections if we have capacity
            if ($scheduler->hasCapacity()) {
                $read = [$this->serverSocket];
                $write = [];
                $except = [];

                /** @var list<Socket> $read */
                $ready = @socket_select($read, $write, $except, 0, 10_000);

                if ($ready !== false && $ready > 0) {
                    /** @var Socket $serverSocket */
                    $serverSocket = $this->serverSocket;
                    $clientSocket = @socket_accept($serverSocket);

                    if ($clientSocket !== false) {
                        $this->collector?->trackFiberSpawn();
                        $scheduler->spawn($clientSocket, function (Socket $socket): void {
                            $this->handleConnection($socket);
                        });
                    }
                }
            }

            // Run one scheduler tick to process existing connections
            $scheduler->tick(0.01);
        }
    }

    /**
     * Handle a single client connection (keep-alive loop).
     *
     * @codeCoverageIgnore Requires real socket connections; delegates to independently tested parser/serializer/sandbox
     */
    private function handleConnection(Socket $clientSocket): void
    {
        // Set receive timeout for idle connections
        socket_set_option($clientSocket, SOL_SOCKET, SO_RCVTIMEO, [
            'sec' => $this->config->keepAliveTimeout,
            'usec' => 0,
        ]);

        $ctx = new ConnectionContext($clientSocket, maxKeepAliveRequests: 100);
        $keepAlive = $this->config->keepAlive;

        try {
            do {
                $ctx->enterPhase('headers');

                // Read data until we have a complete request or timeout
                $result = $this->readAndParse($ctx);

                if ($result === null) {
                    break; // Connection closed or timeout
                }

                if ($result instanceof Response) {
                    // Parse error — send error response and close
                    $raw = $this->serializer->serialize(
                        $result,
                        closeConnection: true,
                        addDateHeader: $this->config->addDateHeader,
                    );
                    $this->socketWrite($clientSocket, $raw);

                    break;
                }

                /** @var Request $request */
                $request = $result;
                $this->requestCount++;
                $memBefore = memory_get_usage(true);
                $startTime = microtime(true);

                // Determine if keep-alive for this request
                $connectionHeader = $request->headers->first('Connection');
                $requestKeepAlive = $keepAlive && $this->isKeepAlive(
                    $connectionHeader,
                    $request->protocolVersion,
                );

                // Run request through sandbox and kernel
                try {
                    $request = $this->beforeRequest($request);
                    $response = $this->kernel->handle($request);
                } catch (Throwable $e) {
                    $this->logger?->error('Request handler error', [
                        'exception' => $e->getMessage(),
                        'path' => $request->path,
                    ]);
                    $response = new Response(
                        body: 'Internal Server Error',
                        status: ResponseStatus::InternalServerError,
                    );
                    // Always close on 5xx
                    $requestKeepAlive = false;
                }

                $this->afterRequest($request, $response);

                // Handle upgrade responses (Kernel::handle() may return UpgradeResponse)
                if ($response instanceof UpgradeResponse) { // @phpstan-ignore instanceof.alwaysFalse
                    $this->handleUpgradeResponse($response, $request, $clientSocket);

                    return; // Socket is now owned by the upgrade handler
                }

                // 5xx always closes
                if ($response->status->isServerError()) {
                    $requestKeepAlive = false;
                }

                // Closing if recycling soon
                $shouldClose = !$requestKeepAlive
                    || !$ctx->decrementKeepAlive()
                    || $this->status !== RuntimeStatus::Running;

                $raw = $this->serializer->serialize(
                    $response,
                    requestMethod: $request->method,
                    closeConnection: $shouldClose,
                    addDateHeader: $this->config->addDateHeader,
                );
                $this->socketWrite($clientSocket, $raw);

                // Record metrics
                $durationMs = (microtime(true) - $startTime) * 1000.0;
                $memDelta = memory_get_usage(true) - $memBefore;

                $this->collector?->recordRequest($request, $response, $durationMs, $memDelta);

                if ($shouldClose) {
                    break;
                }
            } while (true);
        } finally {
            @socket_close($clientSocket);
        }
    }

    /**
     * Read data from socket and attempt to parse a request.
     *
     * @codeCoverageIgnore Requires real socket I/O; parser is independently tested
     */
    private function readAndParse(ConnectionContext $ctx): Request|Response|null
    {
        $deadline = microtime(true) + (float) $this->config->headerTimeoutSeconds;

        while (microtime(true) < $deadline) {
            // Try parsing from existing buffer first
            $result = $this->parser->parse(
                $ctx,
                $this->config->maxHeaderSize,
                $this->config->maxBodySize,
            );

            if ($result !== null) {
                return $result;
            }

            // Need more data — read from socket
            $data = @socket_read($ctx->socket, 8192);

            if ($data === false || $data === '') {
                return null; // Connection closed or error
            }

            $ctx->appendToBuffer($data);
        }

        // Header timeout exceeded
        return new Response(
            body: 'Request Timeout',
            status: ResponseStatus::RequestTimeout,
        );
    }

    private function isKeepAlive(?string $connectionHeader, string $protocolVersion): bool
    {
        if ($connectionHeader !== null) {
            $normalized = strtolower(trim($connectionHeader));

            return $normalized !== 'close';
        }

        // HTTP/1.1 defaults to keep-alive, 1.0 does not
        return $protocolVersion === '1.1';
    }

    private function shouldRecycle(): bool
    {
        if ($this->config->maxRequests > 0 && $this->requestCount >= $this->config->maxRequests) {
            $this->emitRecycle('max_requests');

            return true;
        }

        $memoryMb = (int) ((float) memory_get_usage(true) / 1024.0 / 1024.0);

        if ($this->config->memoryThresholdMb > 0 && $memoryMb >= $this->config->memoryThresholdMb) {
            $this->emitRecycle('memory_threshold');

            return true;
        }

        $uptime = time() - $this->startedAt;

        if ($this->config->timeLimitSeconds > 0 && $uptime >= $this->config->timeLimitSeconds) {
            $this->emitRecycle('time_limit');

            return true;
        }

        return false;
    }

    private function emitRecycle(string $reason): void
    {
        $memoryMb = (int) ((float) memory_get_usage(true) / 1024.0 / 1024.0);
        $uptime = time() - $this->startedAt;

        $this->logger?->info(sprintf(
            'Worker recycling: %s (requests: %d, memory: %dMB, uptime: %ds)',
            $reason,
            $this->requestCount,
            $memoryMb,
            $uptime,
        ));

        $this->collector?->emitWorkerRecycle(
            reason: $reason,
            requestCount: $this->requestCount,
            memoryUsageMb: $memoryMb,
            uptimeSeconds: $uptime,
        );
    }

    /**
     * @codeCoverageIgnore Requires real socket creation and binding
     */
    private function createServerSocket(): Socket
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if ($socket === false) {
            throw RuntimeException::socketError(
                socket_strerror(socket_last_error()),
            );
        }

        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

        if (!@socket_bind($socket, $this->config->host, $this->config->port)) {
            $error = socket_strerror(socket_last_error($socket));
            throw RuntimeException::bindingRefused(
                $this->config->host,
                $this->config->port,
                $error,
            );
        }

        if (!socket_listen($socket, 128)) {
            $error = socket_strerror(socket_last_error($socket));
            throw RuntimeException::socketError(
                sprintf('Failed to listen: %s', $error),
            );
        }

        // Non-blocking for select loop
        socket_set_nonblock($socket);

        return $socket;
    }

    private function shutdownGracefully(): void
    {
        $this->status = RuntimeStatus::Stopping;

        // Drain active Fibers
        $this->scheduler?->drain($this->config->keepAliveTimeout);

        // Close server socket
        if ($this->serverSocket !== null) {
            @socket_close($this->serverSocket);
            $this->serverSocket = null;
        }

        $this->kernel->shutdown();
        $this->status = RuntimeStatus::Stopped;
    }

    /**
     * @codeCoverageIgnore Requires real socket I/O
     */
    private function socketWrite(Socket $socket, string $data): void
    {
        $total = strlen($data);
        $written = 0;

        while ($written < $total) {
            $result = @socket_write($socket, substr($data, $written));

            if ($result === false) {
                break;
            }

            $written += $result;
        }
    }

    /**
     * @codeCoverageIgnore Requires POSIX signal handling (pcntl)
     */
    private function registerSignalHandlers(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        if (!function_exists('pcntl_signal')) {
            return;
        }

        /** @psalm-suppress UndefinedConstant — POSIX-only, guarded by OS check */
        pcntl_signal(SIGINT, function (): void {
            $this->stop();
        });

        /** @psalm-suppress UndefinedConstant */
        pcntl_signal(SIGTERM, function (): void {
            $this->stop();
        });
    }

    private function checkSignals(): void
    {
        if ($this->status === RuntimeStatus::Stopping) {
            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }

    private function registerFatalErrorHandler(): void
    {
        register_shutdown_function(function (): void {
            $error = error_get_last();

            if ($error === null) {
                return;
            }

            $fatalTypes = [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR];

            if (!in_array($error['type'], $fatalTypes, true)) {
                return;
            }

            $this->collector?->emitWorkerRecycle(
                reason: 'fatal_error',
                requestCount: $this->requestCount,
                memoryUsageMb: (int) ((float) memory_get_usage(true) / 1024.0 / 1024.0),
                uptimeSeconds: time() - $this->startedAt,
            );

            // Exit non-zero so process supervisor restarts
            // @codeCoverageIgnoreStart
            exit(1);
            // @codeCoverageIgnoreEnd
        });
    }

    /**
     * @codeCoverageIgnore Requires real socket I/O for upgrade handshake
     */
    private function handleUpgradeResponse(
        UpgradeResponse $response,
        Request $request,
        Socket $clientSocket,
    ): void {
        $raw = $this->serializer->serialize(
            $response,
            requestMethod: $request->method,
            addDateHeader: $this->config->addDateHeader,
        );
        $this->socketWrite($clientSocket, $raw);

        // Transfer socket to upgrade handler
        if ($this->upgradeContext !== null) {
            $response->handler->handleUpgrade($clientSocket, $this->upgradeContext);
        }
    }
}
