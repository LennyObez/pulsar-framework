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
    public function emptyValuesAreAllowed(): void
    {
        $variable = new TemplateVariable('', '');

        self::assertSame('', $variable->name);
        self::assertSame('', $variable->value);
    }

    #[Test]
    public function specialCharactersInValueArePreserved(): void
    {
        $variable = new TemplateVariable('className', 'App\\Models\\User<T>');

        self::assertSame('App\\Models\\User<T>', $variable->value);
    }

    #[Test]
    public function twoVariablesWithSameNameAreDistinctObjects(): void
    {
        $a = new TemplateVariable('namespace', 'App\\Models');
        $b = new TemplateVariable('namespace', 'App\\Services');

        self::assertSame($a->name, $b->name);
        self::assertNotSame($a->value, $b->value);
    }
}
