<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function array_any;
use function array_key_exists;
use function getimagesize;
use function is_array;
use function is_file;
use function is_string;
use function sprintf;

use const UPLOAD_ERR_OK;

/**
 * Image dimensions must meet constraints (min/max width/height).
 * Skips null values.
 */
#[Api(since: '1.0.0')]
readonly class Dimensions implements RuleInterface
{
    public function __construct(
        private ?int $minWidth = null,
        private ?int $maxWidth = null,
        private ?int $minHeight = null,
        private ?int $maxHeight = null,
        private string $message = '',
    ) {}

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            return $this->fail($field);
        }

        if (array_any(['tmp_name', 'error', 'size', 'name', 'type'], static fn(string $key): bool => !array_key_exists($key, $value))) {
            return $this->fail($field);
        }

        if ($value['error'] !== UPLOAD_ERR_OK) {
            return $this->fail($field);
        }

        if (!is_string($value['tmp_name']) || !is_file($value['tmp_name'])) {
            return $this->fail($field);
        }

        $info = @getimagesize($value['tmp_name']);

        if ($info === false) {
            return $this->fail($field);
        }

        [$width, $height] = $info;

        if ($this->minWidth !== null && $width < $this->minWidth) {
            return $this->fail($field);
        }

        if ($this->maxWidth !== null && $width > $this->maxWidth) {
            return $this->fail($field);
        }

        if ($this->minHeight !== null && $height < $this->minHeight) {
            return $this->fail($field);
        }

        if ($this->maxHeight !== null && $height > $this->maxHeight) {
            return $this->fail($field);
        }

        return null;
    }

    private function fail(string $field): Violation
    {
        return new Violation(
            field: $field,
            message: $this->message !== '' ? $this->message : sprintf(
                'The %s field does not meet the required image dimensions.',
                $field,
            ),
            rule: $this->name(),
        );
    }

    #[Override]
    public function name(): string
    {
        return 'dimensions';
    }
}
