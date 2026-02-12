<?php

declare(strict_types=1);

namespace Pulsar\I18n\Format;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Stringable;

use function is_scalar;
use function preg_replace_callback;

/**
 * Simple `{name}` placeholder replacement formatter.
 *
 * Used when ext-intl is not available. Does not support ICU
 * features like plurals or select — only named placeholders.
 * Logs a warning on first use.
 */
#[Internal]
final class FallbackMessageFormatter implements MessageFormatterInterface
{
    private bool $warningLogged = false;

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param array<string, mixed> $parameters
     */
    public function format(string $pattern, array $parameters, string $locale): string
    {
        if ($parameters === []) {
            return $pattern;
        }

        if (!$this->warningLogged && $this->logger !== null) {
            $this->logger->warning('ext-intl not available; ICU features disabled. Using simple placeholder replacement.');
            $this->warningLogged = true;
        }

        return preg_replace_callback(
            '/\{(\w+)}/',
            static function (array $matches) use ($parameters): string {
                $key = $matches[1];

                if (isset($parameters[$key])) {
                    $value = $parameters[$key];

                    return match (true) {
                        $value instanceof Stringable => $value->__toString(),
                        is_scalar($value) => (string) $value,
                        default => $matches[0],
                    };
                }

                return $matches[0];
            },
            $pattern,
        ) ?? $pattern;
    }
}
