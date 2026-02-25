<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Redaction;

use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Redaction\RedactionPipeline;
use Pulsar\Extension\Studio\Console\Redaction\RedactionPolicyInterface;

final class RedactionPipelineTest extends TestCase
{
    #[Test]
    public function redactAppliesGlobalPolicies(): void
    {
        $pipeline = new RedactionPipeline();
        $pipeline->addGlobalPolicy(new class implements RedactionPolicyInterface {
            #[Override]
            public function redact(array $payload): array
            {
                $payload['scrubbed'] = true;
                return $payload;
            }
        });

        $result = $pipeline->redact(['key' => 'value'], EventType::HttpRequest);

        self::assertTrue($result['scrubbed']);
        self::assertSame('value', $result['key']);
    }

    #[Test]
    public function redactAppliesTypePoliciesForMatchingType(): void
    {
        $pipeline = new RedactionPipeline();
        $pipeline->addTypePolicy(EventType::Exception, new class implements RedactionPolicyInterface {
            #[Override]
            public function redact(array $payload): array
            {
                unset($payload['sensitive']);
                return $payload;
            }
        });

        $result = $pipeline->redact(['sensitive' => 'data', 'safe' => 'ok'], EventType::Exception);

        self::assertArrayNotHasKey('sensitive', $result);
        self::assertSame('ok', $result['safe']);
    }

    #[Test]
    public function redactDoesNotApplyTypePoliciesForDifferentType(): void
    {
        $pipeline = new RedactionPipeline();
        $pipeline->addTypePolicy(EventType::Exception, new class implements RedactionPolicyInterface {
            #[Override]
            public function redact(array $payload): array
            {
                unset($payload['sensitive']);
                return $payload;
            }
        });

        $result = $pipeline->redact(['sensitive' => 'data'], EventType::HttpRequest);

        self::assertArrayHasKey('sensitive', $result);
    }

    #[Test]
    public function redactAppliesGlobalBeforeTypePolicies(): void
    {
        $order = [];

        $pipeline = new RedactionPipeline();
        $pipeline->addGlobalPolicy(new class ($order) implements RedactionPolicyInterface {
            public function __construct(private array &$order) {}

            #[Override]
            public function redact(array $payload): array
            {
                $this->order[] = 'global';
                return $payload;
            }
        });
        $pipeline->addTypePolicy(EventType::LogEntry, new class ($order) implements RedactionPolicyInterface {
            public function __construct(private array &$order) {}

            #[Override]
            public function redact(array $payload): array
            {
                $this->order[] = 'type';
                return $payload;
            }
        });

        $pipeline->redact([], EventType::LogEntry);

        self::assertSame(['global', 'type'], $order);
    }

    #[Test]
    public function withDefaultsCreatesConfiguredPipeline(): void
    {
        $pipeline = RedactionPipeline::withDefaults();

        // Verify it redacts password keys (from default policy)
        $result = $pipeline->redact(['password' => 'secret123'], EventType::HttpRequest);

        self::assertSame('[REDACTED]', $result['password']);
    }
}
