<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\OpenApi\Fixture;

use Pulsar\Api\OpenApi\Attribute\ApiField;
use Pulsar\Security\Compliance\DataClassification;

final class WithApiFieldDto
{
    #[ApiField(classification: DataClassification::Confidential, redacted: true)]
    public string $secret = '';
}
