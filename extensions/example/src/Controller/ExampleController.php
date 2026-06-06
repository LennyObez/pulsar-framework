<?php

declare(strict_types=1);

namespace Pulsar\Extension\Example\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Extension\Example\ExampleService;
use Pulsar\Http\Message\Response;

/**
 * Example controller demonstrating route handling with DI.
 */
final readonly class ExampleController
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        private ExampleService $exampleService,
    ) {}

    /**
     * Index action - returns a simple greeting.
     *
     * @noinspection PhpUnusedParameterInspection: route handler contract
     *
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function index(ServerRequestInterface $_request): Response
    {
        return Response::json([
            'message' => $this->exampleService->getGreeting(),
            'status' => 'ok',
        ]);
    }

    /**
     * Info action - returns extension information.
     *
     * @noinspection PhpUnusedParameterInspection: route handler contract
     *
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function info(ServerRequestInterface $_request): Response
    {
        return Response::json($this->exampleService->getInfo());
    }

    /**
     * Greet action - returns a personalized greeting.
     *
     * @noinspection PhpUnusedParameterInspection: route handler contract
     *
     * @param array<string, string> $params Route parameters
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function greet(ServerRequestInterface $_request, array $params = []): Response
    {
        /** @var string $name */
        $name = $params['name'] ?? 'Guest';

        return Response::json([
            'message' => $this->exampleService->getGreeting($name),
        ]);
    }
}
