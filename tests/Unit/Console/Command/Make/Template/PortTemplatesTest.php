<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command\Make\Template;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\Make\Template\PortTemplates;

#[CoversClass(PortTemplates::class)]
final class PortTemplatesTest extends TestCase
{
    private PortTemplates $templates;

    protected function setUp(): void
    {
        $this->templates = new PortTemplates();
    }

    #[Test]
    public function portInterfaceWithMethodsContainsMethodStubs(): void
    {
        $output = $this->templates->portInterface(
            'Payment',
            'App\\Billing',
            ['charge', 'refund'],
        );

        self::assertStringContainsString('namespace App\\Billing\\Contracts;', $output);
        self::assertStringContainsString('interface PaymentInterface', $output);
        self::assertStringContainsString('#[Api(since: \'1.0.0\')]', $output);
        self::assertStringContainsString('public function charge(): void;', $output);
        self::assertStringContainsString('public function refund(): void;', $output);
    }

    #[Test]
    public function portInterfaceWithoutMethodsHasEmptyBody(): void
    {
        $output = $this->templates->portInterface(
            'Storage',
            'App\\FileSystem',
            [],
        );

        self::assertStringContainsString('interface StorageInterface', $output);
        self::assertStringNotContainsString('public function', $output);
    }

    #[Test]
    public function portInterfaceContainsStrictTypesDeclaration(): void
    {
        $output = $this->templates->portInterface('Cache', 'App\\Cache', ['get']);

        self::assertStringContainsString('declare(strict_types=1);', $output);
        self::assertStringContainsString('use Pulsar\\Api\\Api;', $output);
        self::assertStringContainsString('Port interface for Cache operations.', $output);
    }
}
