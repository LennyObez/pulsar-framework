<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_bool;
use function is_int;
use function is_string;

/**
 * File upload security configuration.
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
        $rawMaxSize = $data['max_size'] ?? 10_485_760;

        return new self(
            directory: is_string($data['directory'] ?? null) ? $data['directory'] : 'storage/uploads',
            maxSize: is_int($rawMaxSize) ? $rawMaxSize : 10_485_760,
            regulatedPreset: is_bool($data['regulated_preset'] ?? null) ? $data['regulated_preset'] : false,
        );
    }
}
