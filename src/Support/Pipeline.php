<?php

declare(strict_types=1);

namespace Pulsar\Support;

use Closure;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Sends data through a series of stages.
 *
 * Each stage receives the data and returns the transformed result.
 * Stages are applied in order.
 *
 * Usage:
 *   $result = Pipeline::send($data)
 *       ->through([fn($d) => trim($d), fn($d) => strtolower($d)])
 *       ->thenReturn();
 * @api
 */
#[Api(since: '1.0.0')]
final class Pipeline
{
    private mixed $passable;

    /** @var list<Closure(mixed): mixed> */
    private array $stages = [];

    private function __construct(mixed $passable)
    {
        $this->passable = $passable;
    }

    /**
     * Create a pipeline with the initial data.
     */
    public static function send(mixed $passable): self
    {
        return new self($passable);
    }

    /**
     * Set the stages (pipes) for the pipeline.
     *
     * @param list<Closure(mixed): mixed> $stages
     *
     * @return $this
     */
    public function through(array $stages): self
    {
        $this->stages = $stages;

        return $this;
    }

    /**
     * Add a single stage to the pipeline.
     *
     * @param Closure(mixed): mixed $stage
     *
     * @return $this
     */
    public function pipe(Closure $stage): self
    {
        $this->stages[] = $stage;

        return $this;
    }

    /**
     * Run the pipeline and return the final result.
     */
    #[NoDiscard]
    public function thenReturn(): mixed
    {
        /** @var mixed $passable */
        $passable = $this->passable;

        foreach ($this->stages as $stage) {
            /** @var mixed $passable */
            $passable = $stage($passable);
        }

        return $passable;
    }

    /**
     * Run the pipeline through a final destination closure.
     *
     * @param Closure(mixed): mixed $destination
     */
    #[NoDiscard]
    public function then(Closure $destination): mixed
    {
        /** @var mixed $passable */
        $passable = $this->passable;

        foreach ($this->stages as $stage) {
            /** @var mixed $passable */
            $passable = $stage($passable);
        }

        return $destination($passable);
    }
}
