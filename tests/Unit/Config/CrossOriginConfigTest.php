<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\CrossOriginConfig;

#[CoversClass(CrossOriginConfig::class)]
final class CrossOriginConfigTest extends TestCase
{
    #[Test]
    public function fromArrayCreatesConfig(): void
    {
        $config = CrossOriginConfig::fromArray([
            'opener_policy' => 'same-origin-allow-popups',
            'embedder_policy' => 'require-corp',
            'resource_policy' => 'cross-origin',
        ]);

        self::assertSame('same-origin-allow-popups', $config->openerPolicy);
        self::assertSame('require-corp', $config->embedderPolicy);
        self::assertSame('cross-origin', $config->resourcePolicy);
    }

    #[Test]
    public function coepDisabledByDefault(): void
    {
        $config = new CrossOriginConfig();

        self::assertSame('', $config->embedderPolicy);
    }

    #[Test]
    public function defaultsForCoopAndCorp(): void
    {
        $config = new CrossOriginConfig();

        self::assertSame('same-origin', $config->openerPolicy);
        self::assertSame('same-origin', $config->resourcePolicy);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = CrossOriginConfig::fromArray([]);

        self::assertSame('same-origin', $config->openerPolicy);
        self::assertSame('', $config->embedderPolicy);
        self::assertSame('same-origin', $config->resourcePolicy);
    }
}
