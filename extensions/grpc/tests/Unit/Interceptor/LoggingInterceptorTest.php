<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Tests\Unit\Interceptor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Pulsar\Extension\Grpc\Error\GrpcStatus;
use Pulsar\Extension\Grpc\Handler\MethodDescriptor;
use Pulsar\Extension\Grpc\Handler\MethodType;
use Pulsar\Extension\Grpc\Interceptor\CallContext;
use Pulsar\Extension\Grpc\Interceptor\InterceptorResult;
use Pulsar\Extension\Grpc\Interceptor\LoggingInterceptor;
use Stringable;

use function is_string;

#[CoversClass(LoggingInterceptor::class)]
final class LoggingInterceptorTest extends TestCase
{
    #[Test]
    public function logsSuccessfulCallAtInfoLevel(): void
    {
        $logger = new InMemoryLogger();
        $interceptor = new LoggingInterceptor($logger);
        $context = $this->createContext();

        $result = $interceptor->handle(
            $context,
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('response'),
        );

        self::assertTrue($result->isOk());

        // Should have debug (start) and info (completion) entries
        self::assertCount(2, $logger->logs);
        self::assertSame(LogLevel::DEBUG, $logger->logs[0]['level']);
        self::assertSame(LogLevel::INFO, $logger->logs[1]['level']);
    }

    #[Test]
    public function logsClientErrorAtWarningLevel(): void
    {
        $logger = new InMemoryLogger();
        $interceptor = new LoggingInterceptor($logger);
        $context = $this->createContext();

        $interceptor->handle(
            $context,
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::error(
                GrpcStatus::InvalidArgument,
                'bad input',
            ),
        );

        self::assertSame(LogLevel::WARNING, $logger->logs[1]['level']);
        self::assertSame('bad input', $logger->logs[1]['context']['message']);
    }

    #[Test]
    public function logsServerErrorAtErrorLevel(): void
    {
        $logger = new InMemoryLogger();
        $interceptor = new LoggingInterceptor($logger);
        $context = $this->createContext();

        $interceptor->handle(
            $context,
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::error(
                GrpcStatus::Internal,
                'server crash',
            ),
        );

        self::assertSame(LogLevel::ERROR, $logger->logs[1]['level']);
        self::assertSame('server crash', $logger->logs[1]['context']['message']);
    }

    #[Test]
    public function logsUnauthenticatedAsClientError(): void
    {
        $logger = new InMemoryLogger();
        $interceptor = new LoggingInterceptor($logger);
        $context = $this->createContext();

        $interceptor->handle(
            $context,
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::error(
                GrpcStatus::Unauthenticated,
                'invalid credentials',
            ),
        );

        self::assertSame(LogLevel::WARNING, $logger->logs[1]['level']);
    }

    #[Test]
    public function logsResourceExhaustedAsClientError(): void
    {
        $logger = new InMemoryLogger();
        $interceptor = new LoggingInterceptor($logger);
        $context = $this->createContext();

        $interceptor->handle(
            $context,
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::error(
                GrpcStatus::ResourceExhausted,
                'rate limited',
            ),
        );

        self::assertSame(LogLevel::WARNING, $logger->logs[1]['level']);
    }

    #[Test]
    public function includesMethodNameInLogContext(): void
    {
        $logger = new InMemoryLogger();
        $interceptor = new LoggingInterceptor($logger);
        $context = $this->createContext();

        $interceptor->handle(
            $context,
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('ok'),
        );

        $logContext = $logger->logs[1]['context'];
        self::assertSame('/helloworld.Greeter/SayHello', $logContext['method']);
    }

    #[Test]
    public function includesStatusInLogContext(): void
    {
        $logger = new InMemoryLogger();
        $interceptor = new LoggingInterceptor($logger);
        $context = $this->createContext();

        $interceptor->handle(
            $context,
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('ok'),
        );

        $logContext = $logger->logs[1]['context'];
        self::assertSame('Ok', $logContext['status']);
        self::assertSame(0, $logContext['status_code']);
    }

    #[Test]
    public function includesDurationInLogContext(): void
    {
        $logger = new InMemoryLogger();
        $interceptor = new LoggingInterceptor($logger);
        $context = $this->createContext();

        $interceptor->handle(
            $context,
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('ok'),
        );

        $logContext = $logger->logs[1]['context'];
        self::assertArrayHasKey('duration_ms', $logContext);
        self::assertIsFloat($logContext['duration_ms']);
        self::assertGreaterThanOrEqual(0.0, $logContext['duration_ms']);
    }

    #[Test]
    public function includesAuthIdentityWhenPresent(): void
    {
        $logger = new InMemoryLogger();
        $interceptor = new LoggingInterceptor($logger);
        $context = $this->createContext()->withAttribute('auth.identity', 'user-456');

        $interceptor->handle(
            $context,
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('ok'),
        );

        self::assertSame('user-456', $logger->logs[0]['context']['identity']);
        self::assertSame('user-456', $logger->logs[1]['context']['identity']);
    }

    #[Test]
    public function includesPeerIdentityWhenAuthIdentityMissing(): void
    {
        $logger = new InMemoryLogger();
        $interceptor = new LoggingInterceptor($logger);
        $context = new CallContext(
            method: new MethodDescriptor(
                name: 'SayHello',
                fullName: '/helloworld.Greeter/SayHello',
                type: MethodType::Unary,
                inputType: 'helloworld.HelloRequest',
                outputType: 'helloworld.HelloReply',
                handler: 'App\\Grpc\\GreeterHandler::sayHello',
            ),
            payload: '{}',
            peerIdentity: 'mtls-peer',
        );

        $interceptor->handle(
            $context,
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('ok'),
        );

        self::assertSame('mtls-peer', $logger->logs[0]['context']['identity']);
    }

    #[Test]
    public function logsUnknownStatusAsServerError(): void
    {
        $logger = new InMemoryLogger();
        $interceptor = new LoggingInterceptor($logger);
        $context = $this->createContext();

        $interceptor->handle(
            $context,
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::error(
                GrpcStatus::Unknown,
                'unknown issue',
            ),
        );

        self::assertSame(LogLevel::ERROR, $logger->logs[1]['level']);
    }

    #[Test]
    public function returnsResultUnmodified(): void
    {
        $logger = new InMemoryLogger();
        $interceptor = new LoggingInterceptor($logger);
        $context = $this->createContext();

        $expected = InterceptorResult::ok('exact-payload');
        $result = $interceptor->handle(
            $context,
            static fn(CallContext $ctx): InterceptorResult => $expected,
        );

        self::assertSame($expected->payload, $result->payload);
        self::assertSame($expected->status, $result->status);
    }

    private function createContext(): CallContext
    {
        return new CallContext(
            method: new MethodDescriptor(
                name: 'SayHello',
                fullName: '/helloworld.Greeter/SayHello',
                type: MethodType::Unary,
                inputType: 'helloworld.HelloRequest',
                outputType: 'helloworld.HelloReply',
                handler: 'App\\Grpc\\GreeterHandler::sayHello',
            ),
            payload: '{}',
        );
    }
}

/**
 * Simple in-memory logger for test assertions.
 *
 * @internal
 */
final class InMemoryLogger implements LoggerInterface
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $logs = [];

    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $levelStr = is_string($level) ? $level : '';
        /** @var array<string, mixed> $ctx */
        $ctx = $context;
        $this->logs[] = [
            'level' => $levelStr,
            'message' => (string) $message,
            'context' => $ctx,
        ];
    }
}
