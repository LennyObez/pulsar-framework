<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\ManagedChallenge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\I18n\TranslatorInterface;
use Pulsar\Security\AntiSpam\ManagedChallenge\ManagedChallengeRenderer;
use Pulsar\Security\AntiSpam\ManagedChallenge\ManagedChallengeService;

#[CoversClass(ManagedChallengeRenderer::class)]
final class ManagedChallengeRendererTest extends TestCase
{
    protected function tearDown(): void
    {
        ManagedChallengeRenderer::resetGlobalInstance();
    }

    private function renderer(string $field = 'pulsar-challenge-response'): ManagedChallengeRenderer
    {
        return new ManagedChallengeRenderer(
            new ManagedChallengeService('0123456789abcdef0123456789abcdef', 12, 300),
            $field,
            '/_pulsar/anti-spam/managed-challenge.js',
            '/_pulsar/anti-spam/managed-challenge.worker.js',
        );
    }

    #[Test]
    public function renders_widget_with_embedded_signed_challenge(): void
    {
        $service = new ManagedChallengeService('0123456789abcdef0123456789abcdef', 12, 300);
        $renderer = new ManagedChallengeRenderer(
            $service,
            'pulsar-challenge-response',
            '/_pulsar/anti-spam/managed-challenge.js',
            '/_pulsar/anti-spam/managed-challenge.worker.js',
        );

        $html = $renderer->render();

        self::assertStringContainsString('class="pulsar-managed-challenge"', $html);
        self::assertStringContainsString('data-pmc-bits="12"', $html);
        self::assertStringContainsString('data-pmc-field="pulsar-challenge-response"', $html);
        self::assertStringContainsString('name="pulsar-challenge-response"', $html);
        self::assertStringContainsString('src="/_pulsar/anti-spam/managed-challenge.js"', $html);
        self::assertStringContainsString('data-pmc-worker="/_pulsar/anti-spam/managed-challenge.worker.js"', $html);
        self::assertStringContainsString('<noscript>', $html);

        // The embedded challenge must be a real signed token the service accepts.
        self::assertMatchesRegularExpression('/data-pmc-challenge="([A-Za-z0-9_-]+)"/', $html);
        self::assertSame(1, preg_match('/data-pmc-challenge="([A-Za-z0-9_-]+)"/', $html, $m));
        self::assertTrue(isset($m[1]), 'rendered markup must carry a data-pmc-challenge attribute');
        $parsed = $service->parse($m[1]);
        self::assertNotNull($parsed);

        // The exposed id must match the id inside the signed token.
        self::assertSame(1, preg_match('/data-pmc-id="([0-9a-f]{32})"/', $html, $idMatch));
        self::assertTrue(isset($idMatch[1]), 'rendered markup must carry a data-pmc-id attribute');
        self::assertSame($parsed->id, $idMatch[1]);
    }

    #[Test]
    public function exposes_refresh_url_and_ttl_for_silent_refresh(): void
    {
        $renderer = new ManagedChallengeRenderer(
            new ManagedChallengeService('0123456789abcdef0123456789abcdef', 12, 300),
            'pulsar-challenge-response',
            '/_pulsar/anti-spam/managed-challenge.js',
            '/_pulsar/anti-spam/managed-challenge.worker.js',
            '/_pulsar/anti-spam/managed-challenge/refresh',
            300,
        );

        $html = $renderer->render();

        self::assertStringContainsString('data-pmc-refresh="/_pulsar/anti-spam/managed-challenge/refresh"', $html);
        self::assertStringContainsString('data-pmc-ttl="300"', $html);
    }

    #[Test]
    public function refresh_attributes_default_to_empty_when_not_configured(): void
    {
        // Backward compatible: a renderer built without a refresh endpoint emits
        // an empty refresh URL and zero TTL, so the widget simply never refreshes.
        $html = $this->renderer()->render();

        self::assertStringContainsString('data-pmc-refresh=""', $html);
        self::assertStringContainsString('data-pmc-ttl="0"', $html);
    }

    #[Test]
    public function each_render_mints_a_distinct_challenge(): void
    {
        $renderer = $this->renderer();

        self::assertSame(1, preg_match('/data-pmc-challenge="([^"]+)"/', $renderer->render(), $a));
        self::assertTrue(isset($a[1]), 'first render must carry a data-pmc-challenge attribute');
        self::assertSame(1, preg_match('/data-pmc-challenge="([^"]+)"/', $renderer->render(), $b));
        self::assertTrue(isset($b[1]), 'second render must carry a data-pmc-challenge attribute');

        self::assertNotSame($a[1], $b[1]);
    }

    #[Test]
    public function stamps_csp_nonce_on_the_script_tag(): void
    {
        $html = $this->renderer()->render('nonce-abc123');

        self::assertStringContainsString('<script src="/_pulsar/anti-spam/managed-challenge.js" nonce="nonce-abc123" defer>', $html);
    }

    #[Test]
    public function omits_nonce_attribute_when_no_nonce(): void
    {
        $html = $this->renderer()->render();

        self::assertStringContainsString('<script src="/_pulsar/anti-spam/managed-challenge.js" defer>', $html);
        self::assertStringNotContainsString('nonce=', $html);
    }

    #[Test]
    public function escapes_the_field_name(): void
    {
        $html = $this->renderer('a"><script>x')->render();

        self::assertStringNotContainsString('"><script>x', $html);
        self::assertStringContainsString('&quot;', $html);
    }

    #[Test]
    public function render_global_returns_empty_without_an_instance(): void
    {
        ManagedChallengeRenderer::resetGlobalInstance();

        self::assertSame('', ManagedChallengeRenderer::renderGlobal());
        self::assertSame('', ManagedChallengeRenderer::renderGlobal('nonce'));
    }

    #[Test]
    public function render_global_uses_the_registered_instance(): void
    {
        ManagedChallengeRenderer::setGlobalInstance($this->renderer());

        self::assertStringContainsString('pulsar-managed-challenge', ManagedChallengeRenderer::renderGlobal());
    }

    #[Test]
    public function renders_english_status_strings_by_default(): void
    {
        $html = $this->renderer()->render();

        self::assertStringContainsString(
            '<noscript>This form requires JavaScript to complete a security check.</noscript>',
            $html,
        );
        self::assertStringContainsString('data-pmc-msg-solving="Verifying your request', $html);
        self::assertStringContainsString('data-pmc-msg-solved="Security check complete."', $html);
        self::assertStringContainsString('data-pmc-msg-error="Security verification failed. Please reload the page."', $html);
    }

    #[Test]
    public function resolves_status_strings_from_the_shield_translation_domain(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('has')->willReturnCallback(
            static fn(string $key, ?string $locale, string $domain): bool => $domain === 'shield',
        );
        $translator->method('translate')->willReturnCallback(
            static fn(string $key): string => match ($key) {
                'noscript' => 'Ce formulaire nécessite JavaScript.',
                'verifying' => 'Vérification en cours…',
                'complete' => 'Vérification terminée.',
                'error' => 'Échec de la vérification.',
                default => $key,
            },
        );

        $html = $this->translatedRenderer($translator)->render();

        self::assertStringContainsString('<noscript>Ce formulaire nécessite JavaScript.</noscript>', $html);
        self::assertStringContainsString('data-pmc-msg-solving="Vérification en cours', $html);
        self::assertStringContainsString('data-pmc-msg-error="Échec de la vérification."', $html);
        self::assertStringNotContainsString('This form requires JavaScript', $html);
    }

    #[Test]
    public function falls_back_to_english_when_a_translation_key_is_absent(): void
    {
        // A translator is wired but the host defined no 'shield' keys: the
        // built-in English default must be used — never the raw key (which
        // translate() returns in non-strict mode) nor a strict-mode exception.
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('has')->willReturn(false);
        $translator->method('translate')->willReturnCallback(static fn(string $key): string => $key);

        $html = $this->translatedRenderer($translator)->render();

        self::assertStringContainsString(
            '<noscript>This form requires JavaScript to complete a security check.</noscript>',
            $html,
        );
        self::assertStringContainsString('data-pmc-msg-solving="Verifying your request', $html);
        // The raw key must never leak into the markup.
        self::assertStringNotContainsString('>noscript<', $html);
        self::assertStringNotContainsString('data-pmc-msg-solving="verifying"', $html);
    }

    private function translatedRenderer(TranslatorInterface $translator): ManagedChallengeRenderer
    {
        return new ManagedChallengeRenderer(
            new ManagedChallengeService('0123456789abcdef0123456789abcdef', 12, 300),
            'pulsar-challenge-response',
            '/_pulsar/anti-spam/managed-challenge.js',
            '/_pulsar/anti-spam/managed-challenge.worker.js',
            '',
            0,
            $translator,
        );
    }
}
