<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * File upload security configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class UploadConfig
{
    public function __construct(
        public string $directory,
        public int $maxSize,
        public bool $regulatedPreset,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            directory: Coerce::string($data['directory'] ?? null, 'storage/uploads'),
            maxSize: Coerce::int($data['max_size'] ?? null, 10_485_760),
            regulatedPreset: Coerce::strictBool($data['regulated_preset'] ?? null),
        );
    }
}
