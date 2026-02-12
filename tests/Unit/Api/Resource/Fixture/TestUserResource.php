<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Resource\Fixture;

use Pulsar\Api\Resource\AbstractApiResource;
use Pulsar\Api\Resource\Attribute\ApiResource;
use Pulsar\Api\Resource\Attribute\ClassificationTag;
use Pulsar\Api\Resource\Attribute\Expose;
use Pulsar\Security\Compliance\DataClassification;

#[ApiResource(type: 'users')]
class TestUserResource extends AbstractApiResource
{
    #[Expose]
    public string $id = '';

    #[Expose]
    public string $name = '';

    #[Expose]
    #[ClassificationTag(DataClassification::Confidential)]
    public string $email = '';

    #[Expose(requiredPermissions: ['users.view-ssn'])]
    public string $ssn = '';

    #[Expose(requiredRoles: ['admin'])]
    public string $adminNotes = '';

    // NOT exposed — must NEVER appear in serialized output
    public string $passwordHash = 'hashed_password';

    // NOT exposed — must NEVER appear in serialized output
    public string $internalNotes = 'internal';

    // NOT exposed — must NEVER appear in serialized output
    public int $secretScore = 42;
}
