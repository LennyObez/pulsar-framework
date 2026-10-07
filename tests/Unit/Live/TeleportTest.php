<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Live\Teleport;

#[CoversClass(Teleport::class)]
final class TeleportTest extends TestCase
{
    #[Test]
    public function storesTargetSelector(): void
    {
        $teleport = new Teleport(to: '#modal-root');

        self::assertSame('#modal-root', $teleport->to);
    }

    #[Test]
    public function acceptsBodySelector(): void
    {
        $teleport = new Teleport(to: 'body');

        self::assertSame('body', $teleport->to);
    }
}
