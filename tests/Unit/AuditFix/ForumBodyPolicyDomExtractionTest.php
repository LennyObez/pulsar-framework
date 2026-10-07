<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AuditFix;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Content\ForumBodyPolicy;

/**
 * Verifies that ForumBodyPolicy uses DOM-aware extraction instead of
 * greedy regex for stripping wrapper tags, and handles nested divs correctly.
 */
#[CoversClass(ForumBodyPolicy::class)]
final class ForumBodyPolicyDomExtractionTest extends TestCase
{
    private ForumBodyPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new ForumBodyPolicy();
    }

    #[Test]
    public function nestedDivsAreNotClobberedByGreedyRegex(): void
    {
        // The old greedy regex would consume everything between first <div> and last </div>
        $html = '<p>Before</p><div>Inner 1</div><p>Middle</p><div>Inner 2</div><p>After</p>';
        $result = $this->policy->sanitize($html);

        self::assertStringContainsString('Before', $result);
        self::assertStringContainsString('Middle', $result);
        self::assertStringContainsString('After', $result);
    }

    #[Test]
    public function simpleParagraphIsPreserved(): void
    {
        $html = '<p>Hello <strong>world</strong></p>';
        $result = $this->policy->sanitize($html);

        self::assertStringContainsString('<p>', $result);
        self::assertStringContainsString('Hello', $result);
        self::assertStringContainsString('world', $result);
    }

    #[Test]
    public function dangerousPatternsAreStillEscaped(): void
    {
        $html = '<p>Text</p><script>alert("xss")</script>';
        $result = $this->policy->sanitize($html);

        self::assertStringNotContainsString('<script>', $result);
        self::assertStringContainsString('Text', $result);
    }

    #[Test]
    public function emptyInputReturnsEmpty(): void
    {
        $result = $this->policy->sanitize('');

        self::assertSame('', $result);
    }
}
