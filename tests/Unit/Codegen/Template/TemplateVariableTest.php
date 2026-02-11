<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Codegen\Template;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Codegen\Template\TemplateVariable;

#[CoversClass(TemplateVariable::class)]
final class TemplateVariableTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $variable = new TemplateVariable('entityName', 'BlogPost');

        self::assertSame('entityName', $variable->name);
        self::assertSame('BlogPost', $variable->value);
    }

    #[Test]
    public function emptyValuesAreAllowed(): void
    {
        $variable = new TemplateVariable('', '');

        self::assertSame('', $variable->name);
        self::assertSame('', $variable->value);
    }
}
