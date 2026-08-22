<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Storage;

use InvalidArgumentException;
use Pulsar\Api\Api;
use Pulsar\Security\Crypto\EncryptorInterface;
use RuntimeException;
use Throwable;

use function array_intersect_key;
use function array_key_exists;
use function array_keys;
use function array_map;
use function in_array;
use function is_string;
use function json_decode;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Value object for workflow context data with field-level classification.
 *
 * Every field in the context is tagged with a ClassificationLevel, enabling
 * selective redaction on export and enforcement of classification policies
 * in regulated presets.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ClassifiedContext
{
    /**
     * @param array<string, mixed> $values Field name => value
     * @param array<string, ClassificationLevel> $classifications Field name => classification level
     */
    public function __construct(
        private array $values = [],
        private array $classifications = [],
    ) {}

    /**
     * Set a field with its classification level.
     *
     * Returns a new instance: this object is immutable.
     */
    public function set(string $key, mixed $value, ClassificationLevel $level): self
    {
        return clone($this, [
            'values' => [...$this->values, $key => $value],
            'classifications' => [...$this->classifications, $key => $level],
        ]);
    }

    /**
     * Get a field value.
     *
     * @throws InvalidArgumentException If the field does not exist.
     */
    public function get(string $key): mixed
    {
        if (!array_key_exists($key, $this->values)) {
            throw new InvalidArgumentException(sprintf('Classified field "%s" does not exist', $key));
        }

        return $this->values[$key];
    }

    /**
     * Check if a field exists in the context.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /**
     * Get the classification level of a field.
     *
     * @throws InvalidArgumentException If the field does not exist.
     */
    public function getClassification(string $key): ClassificationLevel
    {
        if (!array_key_exists($key, $this->classifications)) {
            throw new InvalidArgumentException(sprintf('Classified field "%s" does not exist', $key));
        }

        return $this->classifications[$key];
    }

    /**
     * Export context with fields redacted above the given classification level.
     *
     * Fields classified above maxLevel are excluded from the output.
     *
     * @return array<string, mixed>
     */
    public function redactForExport(ClassificationLevel $maxLevel = ClassificationLevel::Internal): array
    {
        $allowedKeys = [];
        foreach ($this->classifications as $key => $fieldLevel) {
            if ($fieldLevel->isAtOrBelow($maxLevel)) {
                $allowedKeys[$key] = true;
            }
        }

        return array_intersect_key($this->values, $allowedKeys);
    }

    /**
     * Get the full context as an array (for storage only).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * Get all field names.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->values);
    }

    /**
     * Get the classification map for serialization.
     *
     * @return array<string, ClassificationLevel>
     */
    public function classificationMap(): array
    {
        return $this->classifications;
    }

    /**
     * Serialize the context with classification metadata for storage.
     *
     * When an encryptor is provided, Restricted and Pii field values are
     * encrypted at rest. Public and Internal fields remain in plaintext.
     * If no encryptor is provided, all fields are stored as plaintext
     * (non-regulated mode).
     *
     * @return array{values: array<string, mixed>, classifications: array<string, string>, encrypted: list<string>}
     */
    public function serialize(?EncryptorInterface $encryptor = null): array
    {
        $classificationStrings = [];
        $serializedValues = [];
        $encryptedFields = [];

        foreach ($this->classifications as $key => $level) {
            $classificationStrings[$key] = $level->value;
        }

        /** @var mixed $value */
        foreach ($this->values as $key => $value) {
            $level = $this->classifications[$key];

            if ($encryptor !== null && $this->requiresEncryption($level)) {
                try {
                    $serializedValues = [...$serializedValues, $key => $encryptor->encrypt(json_encode($value, JSON_THROW_ON_ERROR))];
                } catch (Throwable $e) {
                    throw new RuntimeException(sprintf(
                        'Failed to encrypt workflow context field "%s" (classification: %s): %s',
                        $key,
                        $level->value,
                        $e->getMessage(),
                    ), 0, $e);
                }

                $encryptedFields[] = $key;
            } else {
                $serializedValues = [...$serializedValues, $key => $value];
            }
        }

        return [
            'values' => $serializedValues,
            'classifications' => $classificationStrings,
            'encrypted' => $encryptedFields,
        ];
    }

    /**
     * Reconstruct a ClassifiedContext from serialized storage data.
     *
     * When an encryptor is provided, encrypted fields (tracked in the
     * 'encrypted' key) are decrypted before reconstruction.
     *
     * @param array{values?: array<string, mixed>, classifications?: array<string, string>, encrypted?: list<string>} $data
     */
    public static function fromSerialized(array $data, ?EncryptorInterface $encryptor = null): self
    {
        /** @var array<string, mixed> $rawValues */
        $rawValues = $data['values'] ?? [];

        /** @var array<string, string> $classStrings */
        $classStrings = $data['classifications'] ?? [];

        /** @var list<string> $encryptedFields */
        $encryptedFields = $data['encrypted'] ?? [];

        $classifications = array_map(
            static fn(string $levelString): ClassificationLevel => ClassificationLevel::from($levelString),
            $classStrings,
        );

        $values = [];

        /** @var mixed $value */
        foreach ($rawValues as $key => $value) {
            if ($encryptor !== null && in_array($key, $encryptedFields, true) && is_string($value)) {
                try {
                    $values = [...$values, $key => json_decode($encryptor->decrypt($value), true, 512, JSON_THROW_ON_ERROR)];
                } catch (Throwable $e) {
                    throw new RuntimeException(sprintf(
                        'Failed to decrypt workflow context field "%s": %s',
                        $key,
                        $e->getMessage(),
                    ), 0, $e);
                }
            } else {
                $values = [...$values, $key => $value];
            }
        }

        return new self($values, $classifications);
    }

    /**
     * Check if a classification level requires encryption at rest.
     */
    private function requiresEncryption(ClassificationLevel $level): bool
    {
        return $level === ClassificationLevel::Restricted || $level === ClassificationLevel::Pii;
    }
}
