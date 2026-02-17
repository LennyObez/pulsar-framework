<?php

declare(strict_types=1);

namespace Pulsar\Edge;

use Pulsar\Api\Api;

/**
 * Runs multiple edge functions as a pipeline.
 *
 * The first function to return a non-null EdgeResponse wins.
 * If all functions return null, the request passes through to origin.
 */
#[Api(since: '1.0.0')]
final class EdgeFunctionPipeline
{
    /** @var list<EdgeFunctionInterface> */
    private array $functions = [];

    public function add(EdgeFunctionInterface $function): self
    {
        $this->functions[] = $function;

        return $this;
    }

    /**
     * Process the request through all edge functions.
     *
     * Returns the first non-null response, or null if all pass through.
     */
    public function process(EdgeRequest $request): ?EdgeResponse
    {
        foreach ($this->functions as $function) {
            $response = $function->handle($request);

            if ($response !== null) {
                return $response;
            }
        }

        return null;
    }

    /**
     * @return list<string> Names of all registered edge functions
     */
    public function functionNames(): array
    {
        return array_map(
            fn(EdgeFunctionInterface $f) => $f->name(),
            $this->functions,
        );
    }
}
