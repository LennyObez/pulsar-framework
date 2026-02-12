<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Slug;

#[CoversClass(Slug::class)]
final class SlugTest extends TestCase
{
    private Slug $rule;

    protected function setUp(): void
    {
        $this->rule = new Slug();
    }

    #[Test]
    public function validSlugPasses(): void
    {
        self::assertNull($this->rule->validate('field', 'hello-world', []));
    }

    #[Test]
    public function singleWordSlugPasses(): void
    {
        self::assertNull($this->rule->validate('field', 'hello', []));
    }

    #[Test]
    public function numericSlugPasses(): void
    {
        self::assertNull($this->rule->validate('field', '123', []));
    }

    #[Test]
    public function mixedAlphanumericSlugPasses(): void
    {
        self::assertNull($this->rule->validate('field', 'post-123-draft', []));
    }

    #[Test]
    public function uppercaseFails(): void
    {
        $violation = $this->rule->validate('field', 'Hello-World', []);
        self::assertNotNull($violation);
        self::assertSame('slug', $violation->rule);
    }

    #[Test]
    public function underscoreFails(): void
    {
        $violation = $this->rule->validate('field', 'hello_world', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function leadingHyphenFails(): void
    {
        $violation = $this->rule->validate('field', '-hello', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function trailingHyphenFails(): void
    {
        $violation = $this->rule->validate('field', 'hello-', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function consecutiveHyphensFails(): void
    {
        $violation = $this->rule->validate('field', 'hello--world', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function emptyStringFails(): void
    {
        $violation = $this->rule->validate('field', '', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('field', null, []));
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new Slug(message: 'Invalid slug');
        $violation = $rule->validate('field', 'BAD SLUG', []);
        self::assertNotNull($violation);
        self::assertSame('Invalid slug', $violation->message);
    }
}
