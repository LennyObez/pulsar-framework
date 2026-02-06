<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Make\Template;

/**
 * Template generator for make:webhook-handler scaffolding.
 */
final readonly class WebhookTemplates
{
    public function handlerInterface(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Contracts;

            use Pulsar\\Api\\Api;
            use Pulsar\\Webhook\\WebhookHandlerInterface;

            /**
             * Webhook handler contract for $name events.
             */
            #[Api(since: '1.0.0')]
            interface {$name}WebhookHandlerInterface extends WebhookHandlerInterface
            {
            }
            PHP;
    }

    public function handler(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Internal\\Infrastructure;

            use Override;
            use Psr\\Log\\LoggerInterface;
            use $namespace\\Contracts\\{$name}WebhookHandlerInterface;

            final readonly class {$name}WebhookHandler implements {$name}WebhookHandlerInterface
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
                    \$this->logger->info('Webhook received', [
                        'type' => \$eventType,
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
             * HMAC-SHA256 verifier for $name webhooks.
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
             * In-memory webhook event log for $name webhooks.
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

            use Psr\\Http\\Message\\ServerRequestInterface;
            use Pulsar\\Http\\Message\\Response;
            use Pulsar\\Http\\ResponseStatus;
            use Pulsar\\Webhook\\WebhookProcessor;
            use Pulsar\\Webhook\\WebhookProcessingStatus;

            /**
             * HTTP controller for $name webhook ingestion.
             */
            final readonly class {$name}WebhookController
            {
                private const string SIGNATURE_HEADER = 'X-Webhook-Signature';

                public function __construct(
                    private WebhookProcessor \$processor,
                ) {}

                public function __invoke(ServerRequestInterface \$request): Response
                {
                    \$result = \$this->processor->process(
                        (string) \$request->getBody(),
                        \$request->getHeaderLine(self::SIGNATURE_HEADER),
                    );

                    return match (\$result->status) {
                        WebhookProcessingStatus::Processed => Response::json(
                            ['status' => 'processed', 'event_id' => \$result->eventId],
                            ResponseStatus::OK->value,
                        ),
                        WebhookProcessingStatus::Replay => Response::json(
                            ['status' => 'duplicate', 'event_id' => \$result->eventId],
                            ResponseStatus::OK->value,
                        ),
                        WebhookProcessingStatus::InvalidSignature => Response::json(
                            ['error' => 'Invalid signature'],
                            ResponseStatus::Forbidden->value,
                        ),
                        WebhookProcessingStatus::HandlerError => Response::json(
                            ['error' => 'Processing failed'],
                            ResponseStatus::InternalServerError->value,
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
             * Configuration DTO for $name webhook processing.
             */
            #[Api(since: '1.0.0')]
            final readonly class {$name}WebhookConfig
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
             * Webhook event envelope for $name events.
             */
            final readonly class {$name}WebhookEvent
            {
                /**
                 * @param array<string, mixed> \$payload
                 */
                public function __construct(
                    public string \$id,
                    public {$name}WebhookEventType \$type,
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
                        type: {$name}WebhookEventType::from((string) (\$data['type'] ?? '')),
                        payload: \$data,
                    );
                }
            }
            PHP;
    }

    public function eventType(string $name, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Domain;

            /**
             * Webhook event types for $name.
             */
            enum {$name}WebhookEventType: string
            {
                case Created = 'created';
                case Updated = 'updated';
                case Deleted = 'deleted';
            }
            PHP;
    }

    public function handlerTest(string $name, string $module, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace Pulsar\\Tests\\Unit\\Modules\\$module\\Internal\\Infrastructure;

            use PHPUnit\\Framework\\Attributes\\CoversClass;
            use PHPUnit\\Framework\\Attributes\\Test;
            use PHPUnit\\Framework\\TestCase;
            use Psr\\Log\\NullLogger;
            use $namespace\\Contracts\\{$name}WebhookHandlerInterface;
            use $namespace\\Internal\\Infrastructure\\{$name}WebhookHandler;

            #[CoversClass({$name}WebhookHandler::class)]
            final class {$name}WebhookHandlerTest extends TestCase
            {
                #[Test]
                public function it_implements_the_handler_interface(): void
                {
                    \$handler = new {$name}WebhookHandler(new NullLogger());

                    self::assertInstanceOf({$name}WebhookHandlerInterface::class, \$handler);
                }

                #[Test]
                public function it_handles_webhook_event(): void
                {
                    \$handler = new {$name}WebhookHandler(new NullLogger());

                    \$handler->handle('created', ['id' => 'evt_001']);

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
            use Psr\\Http\\Message\\ServerRequestInterface;
            use Psr\\Http\\Message\\StreamInterface;
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

                    \$body = \$this->createStub(StreamInterface::class);
                    \$body->method('__toString')->willReturn('{}');
                    \$request = \$this->createStub(ServerRequestInterface::class);
                    \$request->method('getBody')->willReturn(\$body);
                    \$request->method('getHeaderLine')->willReturn('sig');

                    \$response = \$controller(\$request);

                    self::assertSame(ResponseStatus::OK->value, \$response->getStatusCode());
                }

                #[Test]
                public function it_returns_forbidden_on_invalid_signature(): void
                {
                    \$processor = \$this->createStub(WebhookProcessor::class);
                    \$processor->method('process')->willReturn(
                        new WebhookProcessingResult(WebhookProcessingStatus::InvalidSignature),
                    );

                    \$controller = new {$name}WebhookController(\$processor);

                    \$body = \$this->createStub(StreamInterface::class);
                    \$body->method('__toString')->willReturn('{}');
                    \$request = \$this->createStub(ServerRequestInterface::class);
                    \$request->method('getBody')->willReturn(\$body);
                    \$request->method('getHeaderLine')->willReturn('bad');

                    \$response = \$controller(\$request);

                    self::assertSame(ResponseStatus::Forbidden->value, \$response->getStatusCode());
                }
            }
            PHP;
    }
}
