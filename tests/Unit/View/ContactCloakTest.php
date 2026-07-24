<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\ContactCloak;

use function base64_encode;

#[CoversClass(ContactCloak::class)]
final class ContactCloakTest extends TestCase
{
    #[Test]
    public function mailNeverEmitsTheLiteralAddress(): void
    {
        $html = ContactCloak::mail('john', 'example.com');

        self::assertStringNotContainsString('john@example.com', $html);
        self::assertStringNotContainsString('@example.com', $html);
        self::assertStringNotContainsString('mailto:', $html);
    }

    #[Test]
    public function mailEncodesUserAndDomainAsBase64DataAttributes(): void
    {
        $html = ContactCloak::mail('john', 'example.com');

        self::assertStringContainsString('class="pulsar-cloak-mail"', $html);
        self::assertStringContainsString('data-u="' . base64_encode('john') . '"', $html);
        self::assertStringContainsString('data-d="' . base64_encode('example.com') . '"', $html);
        // Default no-JS fallback text.
        self::assertStringContainsString('>email</a>', $html);
    }

    #[Test]
    public function mailUsesCustomFallbackTextAndMergesClass(): void
    {
        $html = ContactCloak::mail('a', 'b.co', ['text' => 'Email us', 'class' => 'btn']);

        self::assertStringContainsString('class="pulsar-cloak-mail btn"', $html);
        self::assertStringContainsString('>Email us</a>', $html);
    }

    #[Test]
    public function telNeverEmitsTheLiteralNumberAndEncodesIt(): void
    {
        $html = ContactCloak::tel('32495733136');

        self::assertStringNotContainsString('32495733136', $html);
        self::assertStringNotContainsString('tel:', $html);
        self::assertStringContainsString('class="pulsar-cloak-tel"', $html);
        self::assertStringContainsString('data-n="' . base64_encode('32495733136') . '"', $html);
        self::assertStringContainsString('>call</a>', $html);
    }

    #[Test]
    public function refusesACallerSuppliedHref(): void
    {
        // A caller must not be able to inject an href — the script owns it.
        $html = ContactCloak::mail('a', 'b.co', ['href' => 'https://evil.example']);

        self::assertStringNotContainsString('href=', $html);
    }

    #[Test]
    public function escapesAttributeValues(): void
    {
        $html = ContactCloak::mail('a', 'b.co', ['title' => '"><script>alert(1)</script>']);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('title=', $html);
    }
}
