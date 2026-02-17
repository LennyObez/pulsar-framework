<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Directive\FormDirective;

#[CoversClass(FormDirective::class)]
final class FormDirectiveTest extends TestCase
{
    private FormDirective $directive;

    protected function setUp(): void
    {
        $this->directive = new FormDirective();
    }

    #[Test]
    public function nameReturnsForm(): void
    {
        self::assertSame('form', $this->directive->name());
    }

    #[Test]
    public function compileGeneratesFormOpenTag(): void
    {
        $result = $this->directive->compile('$dto');

        self::assertStringContainsString('<form', $result);
        self::assertStringContainsString('action=', $result);
        self::assertStringContainsString('method=', $result);
    }

    #[Test]
    public function compileIncludesCsrfTokenForNonGetForms(): void
    {
        $result = $this->directive->compile('$dto');

        self::assertStringContainsString('__csrf', $result);
        self::assertStringContainsString('_token', $result);
    }

    #[Test]
    public function compileHandlesMethodSpoofingForPutPatchDelete(): void
    {
        $result = $this->directive->compile("\$dto, ['method' => 'PUT']");

        self::assertStringContainsString('_method', $result);
    }

    #[Test]
    public function compileUsesReflectionForDtoProperties(): void
    {
        $result = $this->directive->compile('$dto');

        self::assertStringContainsString('ReflectionClass', $result);
        self::assertStringContainsString('getProperties', $result);
    }

    #[Test]
    public function compileGeneratesInputTypeBasedOnPropertyType(): void
    {
        $result = $this->directive->compile('$dto');

        self::assertStringContainsString("'number'", $result);
        self::assertStringContainsString("'checkbox'", $result);
        self::assertStringContainsString("'text'", $result);
    }

    #[Test]
    public function compileEscapesOutputForXssPrevention(): void
    {
        $result = $this->directive->compile('$dto');

        self::assertStringContainsString('htmlspecialchars', $result);
    }

    #[Test]
    public function compileWrapsFieldsInDivWithClass(): void
    {
        $result = $this->directive->compile('$dto');

        self::assertStringContainsString('pulse-form-field', $result);
    }
}
