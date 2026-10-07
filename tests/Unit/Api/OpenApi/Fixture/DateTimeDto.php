<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\OpenApi\Fixture;

use DateTime;
use DateTimeImmutable;

final class DateTimeDto
{
    public DateTimeImmutable $createdAt;
    public DateTime $updatedAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTime();
    }
}
