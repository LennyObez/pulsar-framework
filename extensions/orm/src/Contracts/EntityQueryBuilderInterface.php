<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Orm\Domain\FetchPlan;

/**
 * Query builder extension for entity-aware queries.
 *
 * Adds entity hydration and relation loading on top of RowQueryBuilderInterface.
 */
#[Api(since: '1.0.0')]
interface EntityQueryBuilderInterface extends RowQueryBuilderInterface
{
    /**
     * Set the fetch plan for eager relation loading.
     */
    public function withFetchPlan(FetchPlan $fetchPlan): EntityQueryBuilderInterface;

    /**
     * Execute the query and return hydrated entities.
     *
     * @template T of object
     * @return list<T>
     */
    public function getEntities(): array;

    /**
     * Execute the query and return the first hydrated entity.
     *
     * @template T of object
     * @return T|null
     */
    public function firstEntity(): ?object;

    /**
     * Include soft-deleted entities in the results.
     */
    public function withTrashed(): EntityQueryBuilderInterface;

    /**
     * Return only soft-deleted entities.
     */
    public function onlyTrashed(): EntityQueryBuilderInterface;
}
