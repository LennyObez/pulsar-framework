<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\ContactFormBlock;
use Pulsar\Security\AntiSpam\ManagedChallenge\ManagedChallengeRenderer;
use Pulsar\Security\AntiSpam\ManagedChallenge\ManagedChallengeService;
use Pulsar\Security\Csrf\CsrfTokenManagerInterface;

#[CoversClass(ContactFormBlock::class)]
final class ContactFormBlockTest extends TestCase
{
    private ContactFormBlock $block;

    protected function setUp(): void
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn('test-csrf-token-abc123');
        $this->block = new ContactFormBlock($csrf);
    }

    #[Test]
    public function typeReturnsContactForm(): void
    {
        self::assertSame('contact-form', $this->block->type());
    }

    #[Test]
    public function rendersBasicContactForm(): void
    {
        $html = $this->block->render([
            'fields' => [
                ['name' => 'email', 'type' => 'email', 'label' => 'Your Email'],
                ['name' => 'message', 'type' => 'text', 'label' => 'Message'],
            ],
        ]);

        // The legacy unsigned proof-of-work challenge/fields are retired: no
        // client-controlled challenge is minted in the markup anymore.
        self::assertStringNotContainsString('data-pow-challenge', $html);
        self::assertStringNotContainsString('name="_pow_challenge"', $html);
        self::assertStringNotContainsString('name="_pow_nonce"', $html);
        self::assertStringContainsString('<form class="contact-form" method="post" action=""', $html);
        self::assertStringContainsString('<input type="hidden" name="_csrf_token" value="test-csrf-token-abc123">', $html);
        self::assertStringContainsString('<label for="field-email">Your Email</label>', $html);
        self::assertStringContainsString('<input type="email" id="field-email" name="email">', $html);
        self::assertStringContainsString('<label for="field-message">Message</label>', $html);
        self::assertStringContainsString('<input type="text" id="field-message" name="message">', $html);
        self::assertStringContainsString('<button type="submit" class="contact-form__submit">Submit</button>', $html);
    }

    #[Test]
    public function rendersManagedChallengeWidgetWhenConfigured(): void
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn('csrf');
        $renderer = new ManagedChallengeRenderer(
            new ManagedChallengeService('0123456789abcdef0123456789abcdef', 12, 300),
            'pulsar-challenge-response',
            '/_pulsar/anti-spam/managed-challenge.js',
            '/_pulsar/anti-spam/managed-challenge.worker.js',
        );
        $block = new ContactFormBlock($csrf, $renderer);

        $html = $block->render([
            'fields' => [
                ['name' => 'email', 'type' => 'email', 'label' => 'Email'],
            ],
        ]);

        // The signed, single-use managed-challenge widget replaces the retired
        // bespoke proof of work; its hidden token field rides inside the form.
        self::assertStringContainsString('class="pulsar-managed-challenge"', $html);
        self::assertStringContainsString('name="pulsar-challenge-response"', $html);
        self::assertStringEndsWith('</form>', $html);
    }

    #[Test]
    public function rendersHiddenCsrfTokenField(): void
    {
        $html = $this->block->render([
            'fields' => [
                ['name' => 'name', 'type' => 'text', 'label' => 'Name'],
            ],
        ]);

        self::assertStringContainsString(
            '<input type="hidden" name="_csrf_token" value="test-csrf-token-abc123">',
            $html,
        );
    }

    #[Test]
    public function escapesXssInCsrfToken(): void
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn('"><script>xss</script>');
        $block = new ContactFormBlock($csrf);

        $html = $block->render([
            'fields' => [
                ['name' => 'name', 'type' => 'text', 'label' => 'Name'],
            ],
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&quot;&gt;&lt;script&gt;xss&lt;/script&gt;', $html);
    }

    #[Test]
    public function rendersTextareaForTextareaType(): void
    {
        $html = $this->block->render([
            'fields' => [
                ['name' => 'body', 'type' => 'textarea', 'label' => 'Body'],
            ],
        ]);

        self::assertStringContainsString('<textarea id="field-body" name="body"></textarea>', $html);
    }

    #[Test]
    public function rendersInputForNonTextareaTypes(): void
    {
        $html = $this->block->render([
            'fields' => [
                ['name' => 'phone', 'type' => 'tel', 'label' => 'Phone'],
            ],
        ]);

        self::assertStringContainsString('<input type="tel" id="field-phone" name="phone">', $html);
        self::assertStringNotContainsString('<textarea', $html);
    }

    #[Test]
    public function rendersWithCustomSubmitTextAndAction(): void
    {
        $html = $this->block->render([
            'fields' => [
                ['name' => 'name', 'type' => 'text', 'label' => 'Name'],
            ],
            'submitText' => 'Send Message',
            'action' => '/api/contact',
        ]);

        self::assertStringContainsString('action="/api/contact"', $html);
        self::assertStringContainsString('>Send Message</button>', $html);
    }

    #[Test]
    public function rendersDefaultSubmitText(): void
    {
        $html = $this->block->render([
            'fields' => [
                ['name' => 'name', 'type' => 'text', 'label' => 'Name'],
            ],
        ]);

        self::assertStringContainsString('>Submit</button>', $html);
    }

    #[Test]
    public function escapesXssInLabel(): void
    {
        $html = $this->block->render([
            'fields' => [
                ['name' => 'field', 'type' => 'text', 'label' => '<script>xss</script>'],
            ],
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;xss&lt;/script&gt;', $html);
    }

    #[Test]
    public function escapesXssInFormAction(): void
    {
        $html = $this->block->render([
            'fields' => [
                ['name' => 'name', 'type' => 'text', 'label' => 'Name'],
            ],
            'action' => '" onsubmit="alert(1)',
        ]);

        self::assertStringContainsString('action="&quot; onsubmit=&quot;alert(1)', $html);
    }

    #[Test]
    public function escapesXssInFieldName(): void
    {
        $html = $this->block->render([
            'fields' => [
                ['name' => '"><img src=x onerror=alert(1)>', 'type' => 'text', 'label' => 'Test'],
            ],
        ]);

        self::assertStringNotContainsString('<img src=x', $html);
    }

    #[Test]
    public function validatesRequiredFields(): void
    {
        $errors = $this->block->validate([]);

        self::assertContains('fields is required and must be an array', $errors);
    }

    #[Test]
    public function validatesFieldsNotArray(): void
    {
        $errors = $this->block->validate(['fields' => 'invalid']);

        self::assertContains('fields is required and must be an array', $errors);
    }

    #[Test]
    public function validatesFieldMissingName(): void
    {
        $errors = $this->block->validate([
            'fields' => [
                ['type' => 'text', 'label' => 'Test'],
            ],
        ]);

        self::assertContains('fields[0].name is required and must be a string', $errors);
    }

    #[Test]
    public function validatesFieldMissingType(): void
    {
        $errors = $this->block->validate([
            'fields' => [
                ['name' => 'test', 'label' => 'Test'],
            ],
        ]);

        self::assertContains('fields[0].type is required and must be a string', $errors);
    }

    #[Test]
    public function validatesFieldMissingLabel(): void
    {
        $errors = $this->block->validate([
            'fields' => [
                ['name' => 'test', 'type' => 'text'],
            ],
        ]);

        self::assertContains('fields[0].label is required and must be a string', $errors);
    }

    #[Test]
    public function validatesInvalidFieldType(): void
    {
        $errors = $this->block->validate([
            'fields' => [
                ['name' => 'test', 'type' => 'password', 'label' => 'Test'],
            ],
        ]);

        self::assertContains("fields[0].type 'password' is not a valid field type", $errors);
    }

    #[Test]
    public function validDataReturnsNoErrors(): void
    {
        $errors = $this->block->validate([
            'fields' => [
                ['name' => 'email', 'type' => 'email', 'label' => 'Email'],
                ['name' => 'body', 'type' => 'textarea', 'label' => 'Message'],
            ],
        ]);

        self::assertSame([], $errors);
    }
}
