<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Resource\Fixture;

use Pulsar\Api\Resource\AbstractApiResource;
use Pulsar\Api\Resource\Attribute\ApiResource;
use Pulsar\Api\Resource\Attribute\ClassificationTag;
use Pulsar\Api\Resource\Attribute\Expose;
use Pulsar\Api\Resource\Attribute\Filterable;
use Pulsar\Api\Resource\Attribute\Sortable;
use Pulsar\Security\Compliance\DataClassification;

#[ApiResource(type: 'articles', maxFields: 5)]
#[ClassificationTag(DataClassification::Internal)]
class FilterableSortableResource extends AbstractApiResource
{
    #[Expose]
    #[Filterable(operators: ['eq', 'in'])]
    #[Sortable]
    public string $id = '';

    #[Expose(as: 'title')]
    #[Filterable(operators: ['eq', 'contains', 'starts_with'])]
    #[Sortable(defaultDirection: 'desc')]
    public string $articleTitle = '';

    #[Expose]
    public string $body = '';

    #[Expose]
    #[ClassificationTag(DataClassification::Confidential)]
    public string $internalNotes = '';

    // Not exposed
    public string $secretDraft = '';
}
