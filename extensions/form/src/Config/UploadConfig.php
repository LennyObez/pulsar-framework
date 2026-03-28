<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Config;

use NoDiscard;
use Pulsar\Api\Api;

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
     * @param array{
     *     directory?: string,
     *     max_size?: int,
     *     regulated_preset?: bool,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            directory: $data['directory'] ?? 'storage/uploads',
            maxSize: $data['max_size'] ?? 10_485_760,
            regulatedPreset: $data['regulated_preset'] ?? false,
        );
    }
}
