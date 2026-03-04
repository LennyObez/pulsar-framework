<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Dev\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Dev\Mail\MailCaptureDriver;
use Pulsar\Dev\Mail\MailPreviewController;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;
use Pulsar\Mail\Address;
use Pulsar\Mail\Attachment;
use Pulsar\Mail\Message;

#[CoversClass(MailPreviewController::class)]
final class MailPreviewControllerTest extends TestCase
{
    private function createDriver(): MailCaptureDriver
    {
        return new MailCaptureDriver();
    }

    /**
     * Create a request with query params properly set (ServerRequest does not auto-parse URI query).
     *
     * @param array<string, string> $queryParams
     */
    private function createRequest(string $method = 'GET', array $queryParams = []): ServerRequest
    {
        return new ServerRequest(
            method: $method,
            uri: '/',
            queryParams: $queryParams,
        );
    }

    private function createMessage(string $subject = 'Test Email'): Message
    {
        return new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: $subject,
            htmlBody: '<p>HTML body</p>',
            textBody: 'Text body',
        );
    }

    #[Test]
    public function indexRendersEmptyStateWhenNoEmails(): void
    {
        $controller = new MailPreviewController($this->createDriver());
        $request = $this->createRequest(queryParams: ['action' => 'index']);

        $response = $controller->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('No emails captured', $body);
        self::assertStringContainsString('Captured Emails (0)', $body);
    }

    #[Test]
    public function indexListsCapturedEmails(): void
    {
        $driver = $this->createDriver();
        $driver->send($this->createMessage('First Email'));
        $driver->send($this->createMessage('Second Email'));

        $controller = new MailPreviewController($driver);
        $request = $this->createRequest(queryParams: ['action' => 'index']);

        $response = $controller->handle($request);

        $body = (string) $response->getBody();
        self::assertStringContainsString('First Email', $body);
        self::assertStringContainsString('Second Email', $body);
        self::assertStringContainsString('Captured Emails (2)', $body);
    }

    #[Test]
    public function indexShowsEmailMetadata(): void
    {
        $driver = $this->createDriver();
        $driver->send($this->createMessage('Metadata Test'));

        $controller = new MailPreviewController($driver);
        $request = $this->createRequest();

        $response = $controller->handle($request);

        $body = (string) $response->getBody();
        self::assertStringContainsString('sender@example.com', $body);
        self::assertStringContainsString('recipient@example.com', $body);
        self::assertStringContainsString('HTML', $body);
    }

    #[Test]
    public function previewRendersHtmlBodyByDefault(): void
    {
        $driver = $this->createDriver();
        $driver->send($this->createMessage('Preview Test'));
        $captured = $driver->latest();
        self::assertNotNull($captured);

        $controller = new MailPreviewController($driver);
        $request = $this->createRequest(queryParams: ['action' => 'preview', 'id' => $captured->id]);

        $response = $controller->handle($request);

        $body = (string) $response->getBody();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Preview Test', $body);
        self::assertStringContainsString('sender@example.com', $body);
        self::assertStringContainsString('iframe', $body);
    }

    #[Test]
    public function previewRendersTextMode(): void
    {
        $driver = $this->createDriver();
        $driver->send($this->createMessage('Text Preview'));
        $captured = $driver->latest();
        self::assertNotNull($captured);

        $controller = new MailPreviewController($driver);
        $request = $this->createRequest(queryParams: ['action' => 'preview', 'id' => $captured->id, 'mode' => 'text']);

        $response = $controller->handle($request);

        $body = (string) $response->getBody();
        self::assertStringContainsString('Text body', $body);
    }

    #[Test]
    public function previewRendersHeadersMode(): void
    {
        $driver = $this->createDriver();
        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'Headers Test',
            headers: ['X-Custom-Header' => 'custom-value'],
        );
        $driver->send($message);
        $captured = $driver->latest();
        self::assertNotNull($captured);

        $controller = new MailPreviewController($driver);
        $request = $this->createRequest(queryParams: ['action' => 'preview', 'id' => $captured->id, 'mode' => 'headers']);

        $response = $controller->handle($request);

        $body = (string) $response->getBody();
        self::assertStringContainsString('X-Custom-Header', $body);
        self::assertStringContainsString('custom-value', $body);
    }

    #[Test]
    public function previewReturns404ForUnknownId(): void
    {
        $controller = new MailPreviewController($this->createDriver());
        $request = $this->createRequest(queryParams: ['action' => 'preview', 'id' => 'nonexistent']);

        $response = $controller->handle($request);

        self::assertSame(ResponseStatus::NotFound->value, $response->getStatusCode());
    }

    #[Test]
    public function flushClearsAllMessagesAndRedirects(): void
    {
        $driver = $this->createDriver();
        $driver->send($this->createMessage());
        $driver->send($this->createMessage());
        self::assertSame(2, $driver->count());

        $controller = new MailPreviewController($driver);
        $request = $this->createRequest(method: 'POST', queryParams: ['action' => 'flush']);

        $response = $controller->handle($request);

        self::assertSame(ResponseStatus::Found->value, $response->getStatusCode());
        self::assertStringContainsString('action=index', $response->getHeaderLine('Location'));
        self::assertSame(0, $driver->count());
    }

    #[Test]
    public function handleRoutesToIndexByDefault(): void
    {
        $controller = new MailPreviewController($this->createDriver());
        $request = $this->createRequest();

        $response = $controller->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('Captured Emails', $body);
    }

    #[Test]
    public function indexShowsAttachmentCount(): void
    {
        $driver = $this->createDriver();

        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'With attachments',
            attachments: [
                new Attachment('report.pdf', 'data', 'application/pdf'),
                new Attachment('image.png', 'data', 'image/png'),
            ],
        );
        $driver->send($message);

        $controller = new MailPreviewController($driver);
        $request = $this->createRequest();

        $response = $controller->handle($request);

        $body = (string) $response->getBody();
        self::assertStringContainsString('2', $body);
    }

    #[Test]
    public function previewShowsAttachmentList(): void
    {
        $driver = $this->createDriver();

        $message = new Message(
            from: new Address('sender@example.com'),
            to: [new Address('recipient@example.com')],
            subject: 'Attachments preview',
            attachments: [
                new Attachment('report.pdf', 'data', 'application/pdf'),
            ],
        );
        $driver->send($message);
        $captured = $driver->latest();
        self::assertNotNull($captured);

        $controller = new MailPreviewController($driver);
        $request = $this->createRequest(queryParams: ['action' => 'preview', 'id' => $captured->id]);

        $response = $controller->handle($request);

        $body = (string) $response->getBody();
        self::assertStringContainsString('report.pdf', $body);
        self::assertStringContainsString('application/pdf', $body);
    }

    #[Test]
    public function indexEscapesHtmlInSubject(): void
    {
        $driver = $this->createDriver();
        $driver->send($this->createMessage('<script>alert("xss")</script>'));

        $controller = new MailPreviewController($driver);
        $request = $this->createRequest();

        $response = $controller->handle($request);

        $body = (string) $response->getBody();
        self::assertStringNotContainsString('<script>alert', $body);
    }

    #[Test]
    public function previewShowsBackToListLink(): void
    {
        $driver = $this->createDriver();
        $driver->send($this->createMessage());
        $captured = $driver->latest();
        self::assertNotNull($captured);

        $controller = new MailPreviewController($driver);
        $request = $this->createRequest(queryParams: ['action' => 'preview', 'id' => $captured->id]);

        $response = $controller->handle($request);

        $body = (string) $response->getBody();
        self::assertStringContainsString('Back to list', $body);
    }

    #[Test]
    public function previewShowsModeToggleLinks(): void
    {
        $driver = $this->createDriver();
        $driver->send($this->createMessage());
        $captured = $driver->latest();
        self::assertNotNull($captured);

        $controller = new MailPreviewController($driver);
        $request = $this->createRequest(queryParams: ['action' => 'preview', 'id' => $captured->id]);

        $response = $controller->handle($request);

        $body = (string) $response->getBody();
        self::assertStringContainsString('mode=html', $body);
        self::assertStringContainsString('mode=text', $body);
        self::assertStringContainsString('mode=headers', $body);
    }

    #[Test]
    public function indexRendersValidHtml(): void
    {
        $controller = new MailPreviewController($this->createDriver());
        $request = $this->createRequest();

        $response = $controller->handle($request);

        $body = (string) $response->getBody();
        self::assertStringContainsString('<!DOCTYPE html>', $body);
        self::assertStringContainsString('</html>', $body);
        self::assertStringContainsString('Pulsar Studio', $body);
    }
}
