<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Make\Template;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\Make\MethodSignature;
use Pulsar\Console\Command\Make\Template\AdapterTemplates;

#[CoversClass(AdapterTemplates::class)]
final class AdapterTemplatesTest extends TestCase
{
    #[Test]
    public function adapterGeneratesClassWithMethods(): void
    {
        $templates = new AdapterTemplates();
        $methods = [
            new MethodSignature(
                name: 'findById',
                parameters: [
                    ['name' => '$id', 'type' => 'string', 'default' => null],
                ],
                returnType: '?array',
            ),
        ];

        $result = $templates->adapter('PostgresPayment', 'App\\Payments', 'PaymentGateway', $methods);

        self::assertStringContainsString('final readonly class PostgresPayment', $result);
        self::assertStringContainsString('PaymentGatewayInterface', $result);
        self::assertStringContainsString('findById', $result);
        self::assertStringContainsString('string $id', $result);
        self::assertStringContainsString(': ?array', $result);
        self::assertStringContainsString('#[Override]', $result);
        self::assertStringContainsString('LogicException', $result);
    }

    #[Test]
    public function adapterGeneratesEmptyClassWithoutMethods(): void
    {
        $templates = new AdapterTemplates();

        $result = $templates->adapter('StripeAdapter', 'App\\Billing', 'BillingPort', []);

        self::assertStringContainsString('final readonly class StripeAdapter', $result);
        self::assertStringContainsString('BillingPortInterface', $result);
        self::assertStringNotContainsString('#[Override]', $result);
        self::assertStringNotContainsString('LogicException', $result);
    }

    #[Test]
    public function adapterHandlesMethodWithDefaultValues(): void
    {
        $templates = new AdapterTemplates();
        $methods = [
            new MethodSignature(
                name: 'search',
                parameters: [
                    ['name' => '$query', 'type' => 'string', 'default' => null],
                    ['name' => '$limit', 'type' => 'int', 'default' => '50'],
                ],
                returnType: 'array',
            ),
        ];

        $result = $templates->adapter('ElasticSearch', 'App\\Search', 'SearchEngine', $methods);

        self::assertStringContainsString('string $query', $result);
        self::assertStringContainsString('int $limit = 50', $result);
    }

    #[Test]
    public function adapterHandlesMethodWithEmptyType(): void
    {
        $templates = new AdapterTemplates();
        $methods = [
            new MethodSignature(
                name: 'execute',
                parameters: [
                    ['name' => '$data', 'type' => '', 'default' => null],
                ],
                returnType: '',
            ),
        ];

        $result = $templates->adapter('CsvExporter', 'App\\Export', 'Exporter', $methods);

        self::assertStringContainsString('$data', $result);
        self::assertStringNotContainsString(': void', $result);
    }

    #[Test]
    public function adapterTestGeneratesTestClass(): void
    {
        $templates = new AdapterTemplates();

        $result = $templates->adapterTest('PostgresPayment', 'Payments', 'App\\Payments', 'PaymentGateway');

        self::assertStringContainsString('PostgresPaymentTest', $result);
        self::assertStringContainsString('#[CoversClass(PostgresPayment::class)]', $result);
        self::assertStringContainsString('assertInstanceOf', $result);
        self::assertStringContainsString('PaymentGatewayInterface', $result);
    }
}
