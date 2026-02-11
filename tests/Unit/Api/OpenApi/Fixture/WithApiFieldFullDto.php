<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\OpenApi\Fixture;

use Pulsar\Api\OpenApi\Attribute\ApiField;
use Pulsar\Security\Compliance\DataClassification;

final class WithApiFieldFullDto
{
    #[ApiField(
        classification: DataClassification::Restricted,
        accessLevel: 'auditor',
        redacted: true,
        description: 'Social Security Number',
        example: '123-45-6789',
    )]
    public string $ssn = '';
}
