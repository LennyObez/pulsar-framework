<?php

declare(strict_types=1);

namespace Pulsar\Console\Repl;

use Pulsar\Api\Api;
use Pulsar\Attribute\Sensitive;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;
use ReflectionClass;
use ReflectionProperty;

use function in_array;
use function preg_replace;
use function str_replace;
use function strlen;

/**
 * Redacts secrets from REPL output using multiple strategies:
 * known secret values, DSN credentials, #[Sensitive] properties,
 * and the framework SensitiveDataScrubber for array keys.
 * @api
 */
#[Api(since: '1.0.0')]
final class SecretRedactor
{
    private const string REDACTED = '********';

    /** @var list<string> */
    private array $secretValues = [];

    public function __construct(
        private readonly SensitiveDataScrubber $scrubber,
    ) {}

    /**
     * Register a raw secret value to be redacted from output strings.
     */
    public function addSecretValue(string $value): void
    {
        if ($value !== '' && !in_array($value, $this->secretValues, true)) {
            $this->secretValues[] = $value;
        }
    }

    /**
     * Redact known secrets and DSN credentials from an output string.
     */
    public function redactOutput(string $output): string
    {
        // Replace known secret values
        foreach ($this->secretValues as $secret) {
            if (strlen($secret) >= 4) {
                $output = str_replace($secret, self::REDACTED, $output);
            }
        }

        // Redact DSN credentials (://user:pass@ pattern)
        return preg_replace(
            '#(://[^:]+):(.+)@([^@/]+)#',
            '$1:' . self::REDACTED . '@$3',
            $output,
        ) ?? $output;
    }

    /**
     * Produce a redacted array dump of an object's properties.
     *
     * Properties annotated with #[Sensitive] have their values replaced
     * with the redaction marker.
     *
     * @return array<string, mixed>
     */
    public function redactObjectDump(object $obj): array
    {
        $reflection = new ReflectionClass($obj);
        $result = [];

        foreach ($reflection->getProperties() as $property) {
            $name = $property->getName();

            if ($this->hasSensitiveAttribute($property)) {
                $result[$name] = self::REDACTED;
            } else {
                $result[$name] = $property->isInitialized($obj) ? $property->getValue($obj) : '<uninitialized>';
            }
        }

        $result['__class'] = $obj::class;

        return $result;
    }

    /**
     * Scrub sensitive keys from an array using the framework scrubber.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function scrubArray(array $data): array
    {
        return $this->scrubber->scrub($data);
    }

    /**
     * Check if a property has the #[Sensitive] attribute.
     */
    private function hasSensitiveAttribute(ReflectionProperty $property): bool
    {
        return $property->getAttributes(Sensitive::class) !== [];
    }
}
