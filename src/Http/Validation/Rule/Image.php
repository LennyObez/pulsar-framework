<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function array_any;
use function array_key_exists;
use function file_exists;
use function getimagesize;
use function is_array;
use function is_string;
use function sprintf;

use const UPLOAD_ERR_OK;

/**
 * File must be an image (checks mime type via getimagesize or type key).
 * Skips null values.
 */
#[Api(since: '1.0.0')]
readonly class Image implements RuleInterface
{
    public function __construct(
        private string $message = '',
    ) {}

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if ($this->isImage($value)) {
            return null;
        }

        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf('The %s field must be an image.', $field),
            rule: $this->name(),
        );
    }

    private function isImage(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        if (array_any(['tmp_name', 'error', 'size', 'name', 'type'], static fn(string $key): bool => !array_key_exists($key, $value))) {
            return false;
        }

        if ($value['error'] !== UPLOAD_ERR_OK) {
            return false;
        }

        if (!is_string($value['tmp_name']) || !file_exists($value['tmp_name'])) {
            return false;
        }

        $info = @getimagesize($value['tmp_name']);

        return $info !== false;
    }

    #[Override]
    public function name(): string
    {
        return 'image';
    }
}
