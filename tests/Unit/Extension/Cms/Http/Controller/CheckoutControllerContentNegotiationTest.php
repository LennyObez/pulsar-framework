<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\CheckoutServiceInterface;
use Pulsar\Extension\Cms\Http\Controller\CheckoutController;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(CheckoutController::class)]
final class CheckoutControllerContentNegotiationTest extends TestCase
{
    #[Test]
    public function showReturnsJsonForApiClient(): void
    {
        $checkout = $this->createStub(CheckoutServiceInterface::class);
        $controller = new CheckoutController($checkout);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/checkout',
            headers: ['Accept' => 'application/json'],
        );

        $response = $controller->show($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('Cart is empty', (string) $response->getBody());
    }

    #[Test]
    public function showReturnsHtmlForBrowser(): void
    {
        $checkout = $this->createStub(CheckoutServiceInterface::class);
        $controller = new CheckoutController($checkout);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/checkout',
            headers: ['Accept' => 'text/html,application/xhtml+xml'],
        );

        $response = $controller->show($request);

        // Empty cart, but should render HTML with error
        $body = (string) $response->getBody();
        self::assertStringContainsString('<!DOCTYPE html>', $body);
        self::assertStringContainsString('Checkout', $body);
    }

    #[Test]
    public function successReturnsJsonForApiClient(): void
    {
        $checkout = $this->createStub(CheckoutServiceInterface::class);
        $controller = new CheckoutController($checkout);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/checkout/success',
            headers: ['Accept' => 'application/json'],
            queryParams: ['order_id' => 'ord-123'],
        );

        $response = $controller->success($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('confirmed', (string) $response->getBody());
    }

    #[Test]
    public function successReturnsHtmlForBrowser(): void
    {
        $checkout = $this->createStub(CheckoutServiceInterface::class);
        $controller = new CheckoutController($checkout);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/checkout/success',
            headers: ['Accept' => 'text/html,application/xhtml+xml'],
            queryParams: ['order_id' => 'ord-123'],
        );

        $response = $controller->success($request);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('<!DOCTYPE html>', $body);
        self::assertStringContainsString('Thank You', $body);
        self::assertStringContainsString('ord-123', $body);
    }

    #[Test]
    public function successWithMissingOrderIdReturnsHtmlErrorForBrowser(): void
    {
        $checkout = $this->createStub(CheckoutServiceInterface::class);
        $controller = new CheckoutController($checkout);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/checkout/success',
            headers: ['Accept' => 'text/html,application/xhtml+xml'],
        );

        $response = $controller->success($request);

        $body = (string) $response->getBody();
        self::assertStringContainsString('<!DOCTYPE html>', $body);
        self::assertStringContainsString('Order ID is required', $body);
    }

    /**
     * The storefront checkout is the form a shopper actually posts to, so it
     * screens its own body: PCI-DSS 3.4/4.2, no PAN reaches the server.
     */
    #[Test]
    public function processRejectsARawCardNumberInTheBody(): void
    {
        $checkout = $this->createMock(CheckoutServiceInterface::class);
        $checkout->expects(self::never())->method('createOrder');
        $controller = new CheckoutController($checkout);

        $response = $controller->process($this->postWithBody([
            'email' => 'shopper@example.com',
            'card_number' => '4111111111111111',
        ]));

        $body = (string) $response->getBody();

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('PCI-DSS violation', $body);
        self::assertStringContainsString('card_number', $body);
        self::assertStringNotContainsString('4111111111111111', $body);
    }

    #[Test]
    public function processRejectsARawCardNumberNestedUnderThePaymentKey(): void
    {
        $checkout = $this->createMock(CheckoutServiceInterface::class);
        $checkout->expects(self::never())->method('createOrder');
        $controller = new CheckoutController($checkout);

        $response = $controller->process($this->postWithBody([
            'email' => 'shopper@example.com',
            'payment' => ['details' => ['number' => '5500-0000-0000-0004']],
        ]));

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('payment.details.number', (string) $response->getBody());
    }

    /**
     * An unquoted JSON card number decodes to an int, not a string.
     */
    #[Test]
    public function processRejectsACardNumberSentAsAnInteger(): void
    {
        $checkout = $this->createMock(CheckoutServiceInterface::class);
        $checkout->expects(self::never())->method('createOrder');
        $controller = new CheckoutController($checkout);

        $response = $controller->process($this->postWithBody([
            'email' => 'shopper@example.com',
            'payment' => ['number' => 4111111111111111],
        ]));

        self::assertSame(400, $response->getStatusCode());
    }

    /**
     * The screen must not fire on the ordinary fields of a tokenized checkout:
     * a false positive here is a shopper who cannot pay.
     */
    #[Test]
    public function processDoesNotMistakeTokenizedPaymentDataForAPan(): void
    {
        $checkout = $this->createStub(CheckoutServiceInterface::class);
        $controller = new CheckoutController($checkout);

        $response = $controller->process($this->postWithBody([
            'email' => 'shopper@example.com',
            'payment' => ['token' => 'tok_1QZk9x2eZvKYlo2C', 'last4' => '4242', 'brand' => 'visa'],
            'phone' => '+32 470 12 34 56',
        ]));

        $body = (string) $response->getBody();

        // The screen passed; the request then fails on the empty cart instead.
        self::assertStringNotContainsString('PCI-DSS violation', $body);
        self::assertStringContainsString('Cart is empty', $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postWithBody(array $body): ServerRequest
    {
        return new ServerRequest(
            method: 'POST',
            uri: '/en/checkout',
            headers: ['Accept' => 'application/json'],
            parsedBody: $body,
        );
    }
}
