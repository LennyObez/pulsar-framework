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
}
