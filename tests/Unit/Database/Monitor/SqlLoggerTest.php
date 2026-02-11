<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Monitor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Database\Monitor\MonitorConfig;
use Pulsar\Database\Monitor\PiiMasker;
use Pulsar\Database\Monitor\QueryClassifier;
use Pulsar\Database\Monitor\SqlLogger;

use function is_array;
use function is_string;

#[CoversClass(SqlLogger::class)]
#[CoversClass(QueryClassifier::class)]
#[CoversClass(PiiMasker::class)]
final class SqlLoggerTest extends TestCase
{
    #[Test]
    public function logsNormalizedSqlNotRawValues(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $config = new MonitorConfig();

        $logger->expects(self::once())
            ->method('debug')
            ->with(
                'SQL query executed',
                self::callback(static function (array $context): bool {
                    if (!is_string($context['sql'])) {
                        return false;
                    }

                    return str_contains($context['sql'], '?')
                        && !str_contains($context['sql'], 'John');
                }),
            );

        $sqlLogger = new SqlLogger($logger, $config);
        $sqlLogger->log("SELECT * FROM users WHERE name = 'John' AND age = 30", ['John', 30], 5.2, 1);

        self::assertInstanceOf(SqlLogger::class, $sqlLogger);
    }

    #[Test]
    public function logsBindingHashNotRawBindings(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $config = new MonitorConfig();

        $logger->expects(self::once())
            ->method('debug')
            ->with(
                'SQL query executed',
                self::callback(static function (array $context): bool {
                    return isset($context['binding_hash'])
                        && is_string($context['binding_hash'])
                        && $context['binding_hash'] !== ''
                        && !isset($context['bindings']);
                }),
            );

        $sqlLogger = new SqlLogger($logger, $config);
        $sqlLogger->log('SELECT * FROM users WHERE id = ?', [1], 2.0, 1);

        self::assertInstanceOf(SqlLogger::class, $sqlLogger);
    }

    #[Test]
    public function logsDurationAndRowCount(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $config = new MonitorConfig();

        $logger->expects(self::once())
            ->method('debug')
            ->with(
                'SQL query executed',
                self::callback(static function (array $context): bool {
                    return $context['duration_ms'] === 12.5
                        && $context['row_count'] === 42;
                }),
            );

        $sqlLogger = new SqlLogger($logger, $config);
        $sqlLogger->log('SELECT * FROM users', [], 12.5, 42);

        self::assertInstanceOf(SqlLogger::class, $sqlLogger);
    }

    #[Test]
    public function logsQueryClassification(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $config = new MonitorConfig();

        $logger->expects(self::once())
            ->method('debug')
            ->with(
                'SQL query executed',
                self::callback(static function (array $context): bool {
                    return $context['classification'] === 'insert';
                }),
            );

        $sqlLogger = new SqlLogger($logger, $config);
        $sqlLogger->log('INSERT INTO users (name) VALUES (?)', ['Alice'], 3.0, 1);

        self::assertInstanceOf(SqlLogger::class, $sqlLogger);
    }

    #[Test]
    public function debugModeDisabledByDefault(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $config = new MonitorConfig();

        $logger->expects(self::once())
            ->method('debug')
            ->with(
                'SQL query executed',
                self::callback(static function (array $context): bool {
                    return !isset($context['bindings']);
                }),
            );

        $sqlLogger = new SqlLogger($logger, $config);
        $sqlLogger->log('SELECT * FROM users WHERE id = ?', [1], 1.0, 1);

        self::assertInstanceOf(SqlLogger::class, $sqlLogger);
    }

    #[Test]
    public function debugModeRequiresEnvironmentConfirmation(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $config = new MonitorConfig(
            logRawBindings: true,
            requireEnvironmentConfirmation: true,
        );

        $logger->expects(self::once())
            ->method('debug')
            ->with(
                'SQL query executed',
                self::callback(static function (array $context): bool {
                    // Without the env var set, bindings should not be logged
                    // even with logRawBindings=true
                    $envSet = getenv('DB_LOG_RAW_BINDINGS') === 'CONFIRM_UNSAFE';
                    if ($envSet) {
                        return isset($context['bindings']);
                    }

                    return !isset($context['bindings']);
                }),
            );

        $sqlLogger = new SqlLogger($logger, $config);
        $sqlLogger->log('SELECT * FROM users WHERE id = ?', [1], 1.0, 1);

        self::assertInstanceOf(SqlLogger::class, $sqlLogger);
    }

    #[Test]
    public function piiMaskedEvenInDebugMode(): void
    {
        $previousEnv = getenv('DB_LOG_RAW_BINDINGS');
        putenv('DB_LOG_RAW_BINDINGS=CONFIRM_UNSAFE');

        try {
            $logger = $this->createMock(LoggerInterface::class);
            $config = new MonitorConfig(
                logRawBindings: true,
                requireEnvironmentConfirmation: true,
                piiColumns: ['email'],
            );

            $logger->expects(self::once())
                ->method('debug')
                ->with(
                    'SQL query executed',
                    self::callback(static function (array $context): bool {
                        if (!isset($context['bindings']) || !is_array($context['bindings'])) {
                            return false;
                        }

                        /** @var array<string, mixed> $bindings */
                        $bindings = $context['bindings'];

                        return $bindings['email'] === '***MASKED***'
                            && $bindings['name'] === 'John';
                    }),
                );

            $sqlLogger = new SqlLogger($logger, $config);
            $sqlLogger->log(
                'SELECT * FROM users WHERE email = :email AND name = :name',
                ['email' => 'john@example.com', 'name' => 'John'],
                2.0,
                1,
            );

            self::assertInstanceOf(SqlLogger::class, $sqlLogger);
        } finally {
            if ($previousEnv === false) {
                putenv('DB_LOG_RAW_BINDINGS');
            } else {
                putenv('DB_LOG_RAW_BINDINGS=' . $previousEnv);
            }
        }
    }

    #[Test]
    public function productionEnforcementBlocksRawBindings(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $config = new MonitorConfig(
            logRawBindings: false,
            requireEnvironmentConfirmation: true,
        );

        $logger->expects(self::once())
            ->method('debug')
            ->with(
                'SQL query executed',
                self::callback(static function (array $context): bool {
                    return !isset($context['bindings']);
                }),
            );

        $sqlLogger = new SqlLogger($logger, $config);
        $sqlLogger->log('SELECT * FROM users WHERE id = ?', [1], 1.0, 1);

        self::assertInstanceOf(SqlLogger::class, $sqlLogger);
    }
}
