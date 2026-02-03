<?php

declare(strict_types=1);

namespace Pulsar\Extension\Example\Controller;

use Pulsar\Extension\Example\ExampleService;
use Pulsar\Http\Request;
use Pulsar\Http\Response;

/**
 * Example controller demonstrating route handling with DI.
 */
final class ExampleController
{
    public function __construct(
        private readonly ExampleService $exampleService,
    ) {}

    /**
     * Index action - returns a simple greeting.
     */
    public function index(Request $request): Response
    {
        return Response::json([
            'message' => $this->exampleService->getGreeting(),
            'status' => 'ok',
        ]);
    }

    /**
     * Info action - returns extension information.
     */
    public function info(Request $request): Response
    {
        return Response::json($this->exampleService->getInfo());
    }

    /**
     * Greet action - returns a personalized greeting.
     *
     * @param array<string, string> $params Route parameters
     */
    public function greet(Request $request, array $params): Response
    {
        $name = $params['name'] ?? 'Guest';

        return Response::json([
            'message' => $this->exampleService->getGreeting($name),
        ]);
    }
}
