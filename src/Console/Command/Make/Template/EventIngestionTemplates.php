<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Make\Template;

use function array_map;
use function implode;
use function sprintf;
use function strtolower;

/**
 * Template generator for make:event-ingestion scaffolding.
 */
final readonly class EventIngestionTemplates
{
    public function eventHandlerInterface(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Contracts;

            use Pulsar\\Api\\Api;
            use Pulsar\\Webhook\\WebhookHandlerInterface;

            /**
             * Event handler contract for $name ingestion.
             */
            #[Api(since: '1.0.0')]
            interface {$name}EventHandlerInterface extends WebhookHandlerInterface
            {
            }
            PHP;
    }

    public function eventHandler(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Internal\\Infrastructure;

            use Override;
            use Psr\\Log\\LoggerInterface;
            use $namespace\\Contracts\\{$name}EventHandlerInterface;
            use $namespace\\Domain\\{$name}Event;
            use $namespace\\Domain\\{$name}EventType;

            final readonly class {$name}EventHandler implements {$name}EventHandlerInterface
            {
                public function __construct(
                    private LoggerInterface \$logger,
                ) {}

                /**
                 * @param array<string, mixed> \$payload
                 */
                #[Override]
                public function handle(string \$eventType, array \$payload): void
                {
                    \$type = {$name}EventType::tryFrom(\$eventType);

                    if (\$type === null) {
                        \$this->logger->warning('Unknown event type', ['type' => \$eventType]);
                        return;
                    }

                    \$event = {$name}Event::fromArray(\$payload);

                    \$this->logger->info('Event ingested', [
                        'type' => \$type->value,
                        'event_id' => \$event->id,
                    ]);
                }
            }
            PHP;
    }

    public function hmacVerifier(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Internal\\Infrastructure;

            use Override;
            use Pulsar\\Webhook\\Exception\\WebhookException;
            use Pulsar\\Webhook\\WebhookVerifierInterface;

            use function hash_equals;
            use function hash_hmac;

            /**
             * HMAC-SHA256 verifier for $name event ingestion.
             */
            final readonly class {$name}HmacVerifier implements WebhookVerifierInterface
            {
                #[Override]
                public function verify(
                    string \$payload,
                    string \$signatureHeader,
                    string \$secret,
                    int \$toleranceSeconds,
                ): void {
                    \$expected = hash_hmac('sha256', \$payload, \$secret);

                    if (!hash_equals(\$expected, \$signatureHeader)) {
                        throw WebhookException::invalidSignature();
                    }
                }
            }
            PHP;
    }

    public function eventLog(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Internal\\Infrastructure;

            use DateTimeImmutable;
            use Override;
            use Pulsar\\Webhook\\WebhookClaim;
            use Pulsar\\Webhook\\WebhookEventLogInterface;

            /**
             * In-memory event log for $name event deduplication.
             */
            final class InMemory{$name}EventLog implements WebhookEventLogInterface
            {
                /** @var array<string, DateTimeImmutable> */
                private array \$processed = [];

                #[Override]
                public function claim(string \$eventId, DateTimeImmutable \$now, int \$ttlSeconds): WebhookClaim
                {
                    if (isset(\$this->processed[\$eventId])) {
                        return WebhookClaim::replay(\$this->processed[\$eventId]);
                    }

                    return WebhookClaim::claimed();
                }

                #[Override]
                public function commit(string \$eventId): void
                {
                    \$this->processed[\$eventId] = new DateTimeImmutable();
                }

                #[Override]
                public function release(string \$eventId): void
                {
                    unset(\$this->processed[\$eventId]);
                }

                #[Override]
                public function prune(DateTimeImmutable \$before): int
                {
                    \$pruned = 0;
                    foreach (\$this->processed as \$eventId => \$processedAt) {
                        if (\$processedAt <= \$before) {
                            unset(\$this->processed[\$eventId]);
                            \$pruned++;
                        }
                    }

                    return \$pruned;
                }
            }
            PHP;
    }

    public function controller(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Controller;

            use Pulsar\\Http\\Request;
            use Pulsar\\Http\\Response;
            use Pulsar\\Http\\ResponseStatus;
            use Pulsar\\Webhook\\WebhookProcessor;
            use Pulsar\\Webhook\\WebhookProcessingStatus;

            /**
             * HTTP controller for $name event ingestion.
             */
            final readonly class {$name}WebhookController
            {
                private const string SIGNATURE_HEADER = 'X-Webhook-Signature';

                public function __construct(
                    private WebhookProcessor \$processor,
                ) {}

                public function __invoke(Request \$request): Response
                {
                    \$result = \$this->processor->process(
                        \$request->rawBody(),
                        \$request->header(self::SIGNATURE_HEADER) ?? '',
                    );

                    return match (\$result->status) {
                        WebhookProcessingStatus::Processed => Response::json(
                            ['status' => 'processed', 'event_id' => \$result->eventId],
                            ResponseStatus::Ok,
                        ),
                        WebhookProcessingStatus::Replay => Response::json(
                            ['status' => 'duplicate', 'event_id' => \$result->eventId],
                            ResponseStatus::Ok,
                        ),
                        WebhookProcessingStatus::InvalidSignature => Response::json(
                            ['error' => 'Invalid signature'],
                            ResponseStatus::Forbidden,
                        ),
                        WebhookProcessingStatus::HandlerError => Response::json(
                            ['error' => 'Processing failed'],
                            ResponseStatus::InternalServerError,
                        ),
                    };
                }
            }
            PHP;
    }

    public function config(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Config;

            use NoDiscard;
            use Pulsar\\Api\\Api;

            /**
             * Configuration DTO for $name event ingestion.
             */
            #[Api(since: '1.0.0')]
            final readonly class {$name}IngestionConfig
            {
                public function __construct(
                    public string \$secret = '',
                    public int \$toleranceSeconds = 300,
                    public int \$deduplicationTtlSeconds = 259_200,
                ) {}

                /**
                 * @param array<string, mixed> \$data
                 */
                #[NoDiscard]
                public static function fromArray(array \$data): self
                {
                    return new self(
                        secret: (string) (\$data['secret'] ?? ''),
                        toleranceSeconds: (int) (\$data['tolerance_seconds'] ?? 300),
                        deduplicationTtlSeconds: (int) (\$data['deduplication_ttl_seconds'] ?? 259_200),
                    );
                }
            }
            PHP;
    }

    public function event(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Domain;

            use NoDiscard;

            /**
             * Event envelope DTO for $name ingestion.
             */
            final readonly class {$name}Event
            {
                /**
                 * @param array<string, mixed> \$payload
                 */
                public function __construct(
                    public string \$id,
                    public {$name}EventType \$type,
                    public array \$payload,
                ) {}

                /**
                 * @param array<string, mixed> \$data
                 */
                #[NoDiscard]
                public static function fromArray(array \$data): self
                {
                    return new self(
                        id: (string) (\$data['id'] ?? ''),
                        type: {$name}EventType::from((string) (\$data['type'] ?? '')),
                        payload: \$data,
                    );
                }
            }
            PHP;
    }

    /**
     * @param list<string> $events
     */
    public function eventType(string $name, string $namespace, array $events): string
    {
        if ($events === []) {
            $events = ['created', 'updated', 'deleted'];
        }

        $cases = array_map(
            fn(string $e): string => sprintf(
                "    case %s = '%s';",
                str_replace([' ', '-'], '', ucwords(str_replace(['_', '-'], ' ', $e))),
                strtolower($e),
            ),
            $events,
        );

        $caseBlock = implode("\n", $cases);

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Domain;

            /**
             * Event types for $name ingestion.
             */
            enum {$name}EventType: string
            {
            $caseBlock
            }
            PHP;
    }

    public function exception(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Exception;

            use NoDiscard;
            use Pulsar\\Api\\Api;
            use RuntimeException;

            use function sprintf;

            /**
             * $name ingestion exceptions.
             */
            #[Api(since: '1.0.0')]
            final class {$name}IngestionException extends RuntimeException
            {
                #[NoDiscard]
                public static function invalidPayload(string \$reason): self
                {
                    return new self(sprintf('Invalid $name event payload: %s', \$reason));
                }

                #[NoDiscard]
                public static function handlerFailed(string \$eventId, string \$reason): self
                {
                    return new self(sprintf(
                        '$name event handler failed for event "%s": %s',
                        \$eventId,
                        \$reason,
                    ));
                }
            }
            PHP;
    }

    public function serviceProvider(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace;

            use Pulsar\\Container\\ContainerInterface;
            use Pulsar\\Extensibility\\ServiceProviderInterface;
            use $namespace\\Contracts\\{$name}EventHandlerInterface;
            use $namespace\\Internal\\Infrastructure\\{$name}EventHandler;

            final class {$name}IngestionServiceProvider implements ServiceProviderInterface
            {
                public function register(ContainerInterface \$container): void
                {
                    \$container->bind({$name}EventHandlerInterface::class, {$name}EventHandler::class);
                }

                public function provides(): array
                {
                    return [
                        {$name}EventHandlerInterface::class,
                    ];
                }
            }
            PHP;
    }

    public function readme(string $name): string
    {
        return <<<MD
            # $name Event Ingestion

            ## Structure

            - `Contracts/` — Public API interfaces (`#[Api(since: '1.0.0')]`)
            - `Internal/Infrastructure/` — Handler and verifier implementations
            - `Controller/` — Webhook HTTP endpoint
            - `Config/` — Configuration DTOs
            - `Domain/` — Event envelope and type enum
            - `Exception/` — Domain exceptions
            MD;
    }

    public function eventHandlerTest(string $name, string $module, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace Pulsar\\Tests\\Unit\\Modules\\$module\\Internal\\Infrastructure;

            use PHPUnit\\Framework\\Attributes\\CoversClass;
            use PHPUnit\\Framework\\Attributes\\Test;
            use PHPUnit\\Framework\\TestCase;
            use Psr\\Log\\NullLogger;
            use $namespace\\Contracts\\{$name}EventHandlerInterface;
            use $namespace\\Internal\\Infrastructure\\{$name}EventHandler;

            #[CoversClass({$name}EventHandler::class)]
            final class {$name}EventHandlerTest extends TestCase
            {
                #[Test]
                public function it_implements_the_handler_interface(): void
                {
                    \$handler = new {$name}EventHandler(new NullLogger());

                    self::assertInstanceOf({$name}EventHandlerInterface::class, \$handler);
                }

                #[Test]
                public function it_handles_known_event_type(): void
                {
                    \$handler = new {$name}EventHandler(new NullLogger());

                    \$handler->handle('created', ['id' => 'evt_001', 'type' => 'created']);

                    self::assertTrue(true);
                }
            }
            PHP;
    }

    public function controllerTest(string $name, string $module, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace Pulsar\\Tests\\Unit\\Modules\\$module\\Controller;

            use PHPUnit\\Framework\\Attributes\\CoversClass;
            use PHPUnit\\Framework\\Attributes\\Test;
            use PHPUnit\\Framework\\TestCase;
            use Pulsar\\Http\\Request;
            use Pulsar\\Http\\ResponseStatus;
            use Pulsar\\Webhook\\WebhookProcessingResult;
            use Pulsar\\Webhook\\WebhookProcessingStatus;
            use Pulsar\\Webhook\\WebhookProcessor;
            use $namespace\\Controller\\{$name}WebhookController;

            #[CoversClass({$name}WebhookController::class)]
            final class {$name}WebhookControllerTest extends TestCase
            {
                #[Test]
                public function it_returns_ok_on_successful_processing(): void
                {
                    \$processor = \$this->createStub(WebhookProcessor::class);
                    \$processor->method('process')->willReturn(
                        new WebhookProcessingResult(WebhookProcessingStatus::Processed, 'evt_001'),
                    );

                    \$controller = new {$name}WebhookController(\$processor);

                    \$request = \$this->createStub(Request::class);
                    \$request->method('rawBody')->willReturn('{}');
                    \$request->method('header')->willReturn('sig');

                    \$response = \$controller(\$request);

                    self::assertSame(ResponseStatus::Ok, \$response->status);
                }
            }
            PHP;
    }
}
