<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Http\Controller\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Tickets\Http\Controller\Admin\RendersAdminView;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\View\Engine\TemplateEngineInterface;

#[CoversClass(RendersAdminView::class)]
final class RendersAdminViewTest extends TestCase
{
    #[Test]
    public function respondWithViewReturnsJsonWhenAcceptHeaderIsJson(): void
    {
        $controller = $this->createController(templateEngine: null);

        $request = new ServerRequest('GET', '/admin/test')
            ->withHeader('Accept', 'application/json');

        $response = $controller->callRespondWithView($request, 'test.template', ['key' => 'value']);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('value', $body['key']);
    }

    #[Test]
    public function respondWithViewReturnsJsonWhenNoTemplateEngine(): void
    {
        $controller = $this->createController(templateEngine: null);

        $request = new ServerRequest('GET', '/admin/test')
            ->withHeader('Accept', 'text/html');

        $response = $controller->callRespondWithView($request, 'test.template', ['count' => 42]);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(42, $body['count']);
    }

    #[Test]
    public function respondWithViewRendersHtmlWhenTemplateEnginePresent(): void
    {
        $templateEngine = $this->createStub(TemplateEngineInterface::class);
        $templateEngine->method('render')->willReturn('<h1>Dashboard</h1>');

        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);

        $controller = $this->createController(templateEngine: $templateEngine);

        $request = new ServerRequest('GET', '/admin/test')
            ->withHeader('Accept', 'text/html')
            ->withAttribute('identity', $identity);

        $response = $controller->callRespondWithView($request, 'dashboard', ['title' => 'Home']);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<h1>Dashboard</h1>', (string) $response->getBody());
    }

    #[Test]
    public function respondWithViewRespectsCustomStatusCode(): void
    {
        $controller = $this->createController(templateEngine: null);

        $request = new ServerRequest('GET', '/admin/test')
            ->withHeader('Accept', 'application/json');

        $response = $controller->callRespondWithView($request, 'test', ['error' => 'not found'], 404);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function requireIdentityReturnsAuthenticatedIdentity(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);

        $controller = $this->createController();

        $request = new ServerRequest('GET', '/admin/test')
            ->withAttribute('identity', $identity);

        $result = $controller->callRequireIdentity($request);

        self::assertSame($identity, $result);
    }

    #[Test]
    public function requireIdentityThrowsWhenNoIdentity(): void
    {
        $controller = $this->createController();
        $request = new ServerRequest('GET', '/admin/test');

        $this->expectException(AuthenticationException::class);

        $controller->callRequireIdentity($request);
    }

    #[Test]
    public function requireIdentityThrowsWhenNotAuthenticated(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(false);

        $controller = $this->createController();

        $request = new ServerRequest('GET', '/admin/test')
            ->withAttribute('identity', $identity);

        $this->expectException(AuthenticationException::class);

        $controller->callRequireIdentity($request);
    }

    #[Test]
    public function authorizePassesWhenGateAllows(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(false);

        $controller = $this->createController(gate: $gate);

        $identity = $this->createStub(IdentityInterface::class);

        // No exception means authorization passed
        $controller->callAuthorize($identity, 'tickets.view');
        self::assertTrue(true);
    }

    #[Test]
    public function authorizeThrowsWhenGateDenies(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = $this->createController(gate: $gate);

        $identity = $this->createStub(IdentityInterface::class);

        $this->expectException(AuthorizationException::class);

        $controller->callAuthorize($identity, 'tickets.admin');
    }

    /**
     * @return RendersAdminViewTestController
     */
    private function createController(
        ?TemplateEngineInterface $templateEngine = null,
        ?GateInterface $gate = null,
    ): RendersAdminViewTestController {
        return new RendersAdminViewTestController(
            $templateEngine,
            $gate ?? $this->createStub(GateInterface::class),
        );
    }
}
