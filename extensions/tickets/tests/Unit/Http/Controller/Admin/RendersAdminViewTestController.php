<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Tickets\Http\Controller\Admin\RendersAdminView;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

/**
 * Test double that exposes RendersAdminView trait methods for testing.
 */
final class RendersAdminViewTestController
{
    use RendersAdminView;

    public function __construct(
        private readonly ?TemplateEngineInterface $templateEngine,
        private readonly GateInterface $gate,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function callRespondWithView(
        ServerRequestInterface $request,
        string $template,
        array $data,
        int $statusCode = 200,
    ): Response {
        return $this->respondWithView($request, $template, $data, $statusCode);
    }

    public function callRequireIdentity(ServerRequestInterface $request): IdentityInterface
    {
        return $this->requireIdentity($request);
    }

    public function callAuthorize(IdentityInterface $identity, string $permission): void
    {
        $this->authorize($identity, $permission);
    }
}
