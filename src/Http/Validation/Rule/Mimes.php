<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function array_key_exists;
use function array_values;
use function file_exists;
use function finfo_close;
use function finfo_file;
use function finfo_open;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;

use const FILEINFO_MIME_TYPE;
use const UPLOAD_ERR_OK;

/**
 * File mime type must match one of the allowed types.
 * Skips null values.
 */
#[Api(since: '1.0.0')]
readonly class Mimes implements RuleInterface
{
    /** @var list<string> */
    private array $allowedMimes;

    public function __construct(
        string ...$allowedMimes,
    ) {
        $this->allowedMimes = array_values($allowedMimes);
    }

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if ($this->isAllowedMime($value)) {
            return null;
        }

        return new Violation(
            field: $field,
            message: sprintf(
                'The %s field must be a file of type: %s.',
                $field,
                implode(', ', $this->allowedMimes),
            ),
            rule: $this->name(),
        );
    }

    private function isAllowedMime(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        foreach (['tmp_name', 'error', 'size', 'name', 'type'] as $key) {
            if (!array_key_exists($key, $value)) {
                return false;
            }
        }

        if ($value['error'] !== UPLOAD_ERR_OK) {
            return false;
        }

        // Try finfo for real file detection
        if (is_string($value['tmp_name']) && file_exists($value['tmp_name'])) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                $mime = finfo_file($finfo, $value['tmp_name']);
                finfo_close($finfo);

                return is_string($mime) && in_array($mime, $this->allowedMimes, true);
            }
        }

        // Fall back to the type key
        return is_string($value['type']) && in_array($value['type'], $this->allowedMimes, true);
    }

    #[Override]
    public function name(): string
    {
        return 'mimes';
    }
}
