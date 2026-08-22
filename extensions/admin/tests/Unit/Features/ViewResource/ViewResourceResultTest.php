<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\ViewResource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Features\ViewResource\ViewResourceResult;

#[CoversClass(ViewResourceResult::class)]
final class ViewResourceResultTest extends TestCase
{
    #[Test]
    public function constructorSetsData(): void
    {
        $data = ['id' => '1', 'name' => 'John', 'email' => 'john@example.com'];

        $result = new ViewResourceResult(data: $data);

        self::assertSame($data, $result->data);
    }

    #[Test]
    public function emptyData(): void
    {
        $result = new ViewResourceResult(data: []);

        self::assertSame([], $result->data);
    }
}
