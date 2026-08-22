<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Escaper;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Escaper\EscapeContext;

final class EscapeContextTest extends TestCase
{
    #[Test]
    public function allCasesHaveStringValues(): void
    {
        foreach (EscapeContext::cases() as $case) {
            self::assertNotEmpty($case->value);
        }
    }

    #[Test]
    public function expectedCasesExist(): void
    {
        self::assertSame('html', EscapeContext::Html->value);
        self::assertSame('attr', EscapeContext::Attribute->value);
        self::assertSame('js', EscapeContext::JavaScript->value);
        self::assertSame('css', EscapeContext::Css->value);
        self::assertSame('url', EscapeContext::Url->value);
        self::assertSame('url_full', EscapeContext::UrlFull->value);
    }

    #[Test]
    public function totalCaseCount(): void
    {
        self::assertCount(6, EscapeContext::cases());
    }
}
