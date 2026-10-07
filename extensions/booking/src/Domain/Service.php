<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Domain;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\Payments\Domain\Money;

/**
 * Bookable service entity.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Service
{
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public int $duration,
        public Money $basePrice,
        public int $depositPercent,
        public ?string $categoryId,
        public bool $active,
    ) {}

    /**
     * Calculate the deposit amount for this service.
     */
    #[NoDiscard]
    public function depositAmount(): Money
    {
        return $this->basePrice->percentage($this->depositPercent * 100);
    }

    /**
     * Create an inactive copy of this service.
     */
    #[NoDiscard]
    public function deactivate(): self
    {
        return clone($this, ['active' => false]);
    }

    /**
     * Create an active copy of this service.
     */
    #[NoDiscard]
    public function activate(): self
    {
        return clone($this, ['active' => true]);
    }
}
