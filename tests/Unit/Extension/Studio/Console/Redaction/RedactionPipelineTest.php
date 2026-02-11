<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Redaction;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Redaction\DefaultRedactionPolicy;
use Pulsar\Extension\Studio\Console\Redaction\RedactionPipeline;
use Pulsar\Extension\Studio\Console\Redaction\RedactionPolicyInterface;

#[CoversClass(RedactionPipeline::class)]
final class RedactionPipelineTest extends TestCase
{
    #[Test]
    public function emptyPipelineReturnsPayloadUnchanged(): void
    {
        $pipeline = new RedactionPipeline();
        $payload = ['key' => 'value', 'other' => 123];

        $result = $pipeline->redact($payload, EventType::HttpRequest);

        self::assertSame($payload, $result);
    }

    #[Test]
    public function globalPolicyAppliedToAllEventTypes(): void
    {
        $pipeline = new RedactionPipeline();

        $policy = $this->createStub(RedactionPolicyInterface::class);
        $policy->method('redact')->willReturnCallback(function (array $payload): array {
            $payload['redacted'] = true;

            return $payload;
        });

        $pipeline->addGlobalPolicy($policy);

        $result1 = $pipeline->redact(['key' => 'val'], EventType::HttpRequest);
        self::assertTrue($result1['redacted']);

        $result2 = $pipeline->redact(['key' => 'val'], EventType::DatabaseQuery);
        self::assertTrue($result2['redacted']);
    }

    #[Test]
    public function typePolicyAppliedOnlyToSpecificEventType(): void
    {
        $pipeline = new RedactionPipeline();

        $policy = $this->createStub(RedactionPolicyInterface::class);
        $policy->method('redact')->willReturnCallback(function (array $payload): array {
            $payload['db_redacted'] = true;

            return $payload;
        });

        $pipeline->addTypePolicy(EventType::DatabaseQuery, $policy);

        $resultDb = $pipeline->redact(['key' => 'val'], EventType::DatabaseQuery);
        self::assertTrue($resultDb['db_redacted']);

        $resultHttp = $pipeline->redact(['key' => 'val'], EventType::HttpRequest);
        self::assertArrayNotHasKey('db_redacted', $resultHttp);
    }

    #[Test]
    public function globalPoliciesAppliedBeforeTypePolicies(): void
    {
        $pipeline = new RedactionPipeline();

        $order = [];

        $globalPolicy = $this->createStub(RedactionPolicyInterface::class);
        $globalPolicy->method('redact')->willReturnCallback(function (array $payload) use (&$order): array {
            $order[] = 'global';
            $payload['global'] = true;

            return $payload;
        });

        $typePolicy = $this->createStub(RedactionPolicyInterface::class);
        $typePolicy->method('redact')->willReturnCallback(function (array $payload) use (&$order): array {
            $order[] = 'type';
            $payload['type'] = true;

            return $payload;
        });

        $pipeline->addGlobalPolicy($globalPolicy);
        $pipeline->addTypePolicy(EventType::HttpRequest, $typePolicy);

        $result = $pipeline->redact(['key' => 'val'], EventType::HttpRequest);

        self::assertSame(['global', 'type'], $order);
        self::assertTrue($result['global']);
        self::assertTrue($result['type']);
    }

    #[Test]
    public function multipleGlobalPoliciesChainedInOrder(): void
    {
        $pipeline = new RedactionPipeline();

        $policy1 = $this->createStub(RedactionPolicyInterface::class);
        $policy1->method('redact')->willReturnCallback(function (array $payload): array {
            $payload['step1'] = true;

            return $payload;
        });

        $policy2 = $this->createStub(RedactionPolicyInterface::class);
        $policy2->method('redact')->willReturnCallback(function (array $payload): array {
            $payload['step2'] = isset($payload['step1']);

            return $payload;
        });

        $pipeline->addGlobalPolicy($policy1);
        $pipeline->addGlobalPolicy($policy2);

        $result = $pipeline->redact([], EventType::HttpRequest);

        self::assertTrue($result['step1']);
        self::assertTrue($result['step2']);
    }

    #[Test]
    public function multipleTypePoliciesChainedInOrder(): void
    {
        $pipeline = new RedactionPipeline();

        $policy1 = $this->createStub(RedactionPolicyInterface::class);
        $policy1->method('redact')->willReturnCallback(function (array $payload): array {
            $payload['type_step1'] = true;

            return $payload;
        });

        $policy2 = $this->createStub(RedactionPolicyInterface::class);
        $policy2->method('redact')->willReturnCallback(function (array $payload): array {
            $payload['type_step2'] = isset($payload['type_step1']);

            return $payload;
        });

        $pipeline->addTypePolicy(EventType::DatabaseQuery, $policy1);
        $pipeline->addTypePolicy(EventType::DatabaseQuery, $policy2);

        $result = $pipeline->redact([], EventType::DatabaseQuery);

        self::assertTrue($result['type_step1']);
        self::assertTrue($result['type_step2']);
    }

    #[Test]
    public function withDefaultsCreatesDefaultPipeline(): void
    {
        $pipeline = RedactionPipeline::withDefaults();

        // The default pipeline should have at least the DefaultRedactionPolicy as global
        // It should redact known sensitive keys
        $payload = ['password' => 'secret123', 'username' => 'admin'];
        $result = $pipeline->redact($payload, EventType::HttpRequest);

        // password should be redacted by the DefaultRedactionPolicy
        self::assertNotSame('secret123', $result['password']);
    }

    #[Test]
    public function typePolicyNotAppliedToOtherTypes(): void
    {
        $pipeline = new RedactionPipeline();

        $policy = $this->createStub(RedactionPolicyInterface::class);
        $policy->method('redact')->willReturnCallback(function (array $payload): array {
            $payload['exception_redacted'] = true;

            return $payload;
        });

        $pipeline->addTypePolicy(EventType::Exception, $policy);

        $result = $pipeline->redact(['data' => 1], EventType::HttpResponse);
        self::assertArrayNotHasKey('exception_redacted', $result);

        $result = $pipeline->redact(['data' => 1], EventType::Exception);
        self::assertTrue($result['exception_redacted']);
    }
}
