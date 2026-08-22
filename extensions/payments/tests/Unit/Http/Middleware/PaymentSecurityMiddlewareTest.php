<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Http\Middleware;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Extension\Payments\Http\Middleware\PaymentSecurityMiddleware;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(PaymentSecurityMiddleware::class)]
final class PaymentSecurityMiddlewareTest extends TestCase
{
    #[Test]
    public function rejectsPostBodyCarryingRawCardNumber(): void
    {
        $handler = new RecordingHandler();

        $response = new PaymentSecurityMiddleware()->process(
            self::request('POST', ['card_number' => '4111111111111111']),
            $handler,
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('PCI-DSS compliance violation', (string) $response->getBody());
        self::assertStringContainsString('card_number', (string) $response->getBody());
        self::assertFalse($handler->called, 'The handler ran despite a PAN in the body');
    }

    #[Test]
    public function rejectionNeverEchoesTheCardNumber(): void
    {
        $response = new PaymentSecurityMiddleware()->process(
            self::request('POST', ['card_number' => '4111111111111111']),
            new RecordingHandler(),
        );

        self::assertStringNotContainsString('4111111111111111', (string) $response->getBody());
    }

    #[Test]
    public function rejectsCardNumberNestedInsideTheBody(): void
    {
        $handler = new RecordingHandler();

        $response = new PaymentSecurityMiddleware()->process(
            self::request('POST', ['payment' => ['details' => ['number' => '5500 0000 0000 0004']]]),
            $handler,
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('payment.details.number', (string) $response->getBody());
        self::assertFalse($handler->called);
    }

    /**
     * An unquoted JSON card number decodes to an int, not a string.
     */
    #[Test]
    public function rejectsCardNumberSentAsAnInteger(): void
    {
        $handler = new RecordingHandler();

        $response = new PaymentSecurityMiddleware()->process(
            self::request('POST', ['card_number' => 4111111111111111]),
            $handler,
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertFalse($handler->called);
    }

    #[Test]
    public function rejectsPanOnPutAndPatchAsWellAsPost(): void
    {
        foreach (['PUT', 'PATCH'] as $method) {
            $handler = new RecordingHandler();

            $response = new PaymentSecurityMiddleware()->process(
                self::request($method, ['card_number' => '4111111111111111']),
                $handler,
            );

            self::assertSame(400, $response->getStatusCode(), "$method was not screened");
            self::assertFalse($handler->called, "$method reached the handler");
        }
    }

    #[Test]
    public function passesCleanBodyThroughToTheHandler(): void
    {
        $handler = new RecordingHandler();

        $response = new PaymentSecurityMiddleware()->process(
            self::request('POST', [
                'amount' => 1000,
                'currency' => 'EUR',
                'payment' => ['token' => 'tok_abc123', 'last4' => '4242'],
            ]),
            $handler,
        );

        self::assertSame(204, $response->getStatusCode());
        self::assertTrue($handler->called);
    }

    #[Test]
    public function passesBodylessMethodsThroughUnscreened(): void
    {
        $handler = new RecordingHandler();

        $response = new PaymentSecurityMiddleware()->process(
            self::request('GET', ['card_number' => '4111111111111111']),
            $handler,
        );

        self::assertSame(204, $response->getStatusCode());
        self::assertTrue($handler->called);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function request(string $method, array $body): ServerRequest
    {
        return new ServerRequest(
            method: $method,
            uri: '/payments/checkout',
            parsedBody: $body,
        );
    }
}

final class RecordingHandler implements RequestHandlerInterface
{
    public bool $called = false;

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->called = true;

        return Response::noContent();
    }
}
