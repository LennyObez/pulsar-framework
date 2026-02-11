<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Interceptor;

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

#[CoversClass(LoggingInterceptor::class)]
final class LoggingInterceptorTest extends TestCase
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $logs = [];

    private function makeLogger(): LoggerInterface
    {
        $test = $this;

        return new class ($test) implements LoggerInterface {
            public function __construct(private readonly LoggingInterceptorTest $test) {}

            /** @param array<mixed> $context */
            private function record(string $level, string $message, array $context): void
            {
                /** @var array<string, mixed> $typedContext */
                $typedContext = $context;
                $this->test->logs[] = ['level' => $level, 'message' => $message, 'context' => $typedContext];
            }

            public function emergency(Stringable|string $message, array $context = []): void
            {
                $this->record(LogLevel::EMERGENCY, (string) $message, $context);
            }

            public function alert(Stringable|string $message, array $context = []): void
            {
                $this->record(LogLevel::ALERT, (string) $message, $context);
            }

            public function critical(Stringable|string $message, array $context = []): void
            {
                $this->record(LogLevel::CRITICAL, (string) $message, $context);
            }

            public function error(Stringable|string $message, array $context = []): void
            {
                $this->record(LogLevel::ERROR, (string) $message, $context);
            }

            public function warning(Stringable|string $message, array $context = []): void
            {
                $this->record(LogLevel::WARNING, (string) $message, $context);
            }

            public function notice(Stringable|string $message, array $context = []): void
            {
                $this->record(LogLevel::NOTICE, (string) $message, $context);
            }

            public function info(Stringable|string $message, array $context = []): void
            {
                $this->record(LogLevel::INFO, (string) $message, $context);
            }

            public function debug(Stringable|string $message, array $context = []): void
            {
                $this->record(LogLevel::DEBUG, (string) $message, $context);
            }

            public function log($level, Stringable|string $message, array $context = []): void
            {
                /** @var string $levelStr */
                $levelStr = $level;
                $this->record($levelStr, (string) $message, $context);
            }
        };
    }

    private function makeContext(): CallContext
    {
        return new CallContext(
            method: new MethodDescriptor(
                name: 'GetUser',
                fullName: '/users.UserService/GetUser',
                type: MethodType::Unary,
                inputType: 'GetUserReq',
                outputType: 'GetUserRes',
                handler: 'UserService::getUser',
            ),
            payload: '',
        );
    }

    #[Test]
    public function logsSuccessfulCallAtInfoLevel(): void
    {
        $interceptor = new LoggingInterceptor($this->makeLogger());

        $interceptor->handle(
            $this->makeContext(),
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('ok'),
        );

        self::assertCount(2, $this->logs);
        self::assertSame(LogLevel::DEBUG, $this->logs[0]['level']);
        self::assertSame(LogLevel::INFO, $this->logs[1]['level']);
        self::assertStringContainsString('completed', $this->logs[1]['message']);
    }

    #[Test]
    public function logsClientErrorAtWarningLevel(): void
    {
        $interceptor = new LoggingInterceptor($this->makeLogger());

        $interceptor->handle(
            $this->makeContext(),
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::error(
                GrpcStatus::NotFound,
                'Resource not found',
            ),
        );

        self::assertSame(LogLevel::WARNING, $this->logs[1]['level']);
        self::assertStringContainsString('client error', $this->logs[1]['message']);
    }

    #[Test]
    public function logsServerErrorAtErrorLevel(): void
    {
        $interceptor = new LoggingInterceptor($this->makeLogger());

        $interceptor->handle(
            $this->makeContext(),
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::error(
                GrpcStatus::Internal,
                'Internal server error',
            ),
        );

        self::assertSame(LogLevel::ERROR, $this->logs[1]['level']);
        self::assertStringContainsString('server error', $this->logs[1]['message']);
    }

    #[Test]
    public function includesMethodNameInLogs(): void
    {
        $interceptor = new LoggingInterceptor($this->makeLogger());

        $interceptor->handle(
            $this->makeContext(),
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('ok'),
        );

        self::assertSame('/users.UserService/GetUser', $this->logs[0]['context']['method']);
    }

    #[Test]
    public function includesDurationInCompletionLog(): void
    {
        $interceptor = new LoggingInterceptor($this->makeLogger());

        $interceptor->handle(
            $this->makeContext(),
            static fn(CallContext $ctx): InterceptorResult => InterceptorResult::ok('ok'),
        );

        self::assertArrayHasKey('duration_ms', $this->logs[1]['context']);
        self::assertIsFloat($this->logs[1]['context']['duration_ms']);
    }

    #[Test]
    public function recognizesAllClientErrorStatuses(): void
    {
        $clientStatuses = [
            GrpcStatus::InvalidArgument,
            GrpcStatus::NotFound,
            GrpcStatus::AlreadyExists,
            GrpcStatus::PermissionDenied,
            GrpcStatus::FailedPrecondition,
            GrpcStatus::OutOfRange,
            GrpcStatus::Unauthenticated,
            GrpcStatus::ResourceExhausted,
            GrpcStatus::Cancelled,
        ];

        foreach ($clientStatuses as $status) {
            $this->logs = [];
            $interceptor = new LoggingInterceptor($this->makeLogger());

            $interceptor->handle(
                $this->makeContext(),
                static fn(CallContext $ctx): InterceptorResult => InterceptorResult::error($status, 'err'),
            );

            self::assertCount(2, $this->logs);
            self::assertSame(LogLevel::WARNING, $this->logs[1]['level'], "Expected WARNING for $status->name");
        }
    }
}
