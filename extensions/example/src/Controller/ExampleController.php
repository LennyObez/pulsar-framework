<?php

declare(strict_types=1);

namespace Pulsar\Extension\Example\Controller;

use Pulsar\Extension\Example\ExampleService;
use Pulsar\Http\Request;
use Pulsar\Http\Response;

/**
 * Example controller demonstrating route handling with DI.
 */
final readonly class ExampleController
{
    public function __construct(
        private ExampleService $exampleService,
    ) {}

    /**
     * Index action - returns a simple greeting.
     */
    /** @noinspection PhpUnusedParameterInspection — route handler contract */
    public function index(Request $_request): Response
    {
        return Response::json([
            'message' => $this->exampleService->getGreeting(),
            'status' => 'ok',
        ]);
    }

    /**
     * Info action - returns extension information.
     */
    /** @noinspection PhpUnusedParameterInspection — route handler contract */
    public function info(Request $_request): Response
    {
        return Response::json($this->exampleService->getInfo());
    }

    /**
     * Greet action - returns a personalized greeting.
     *
     * @param array<string, string> $params Route parameters
     */
    /** @noinspection PhpUnusedParameterInspection — route handler contract */
    public function greet(Request $_request, array $params): Response
    {
        $name = $params['name'] ?? 'Guest';

        return Response::json([
            'message' => $this->exampleService->getGreeting($name),
        ]);
    }
}
