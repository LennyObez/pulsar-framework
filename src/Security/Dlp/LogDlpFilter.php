<?php

declare(strict_types=1);

namespace Pulsar\Security\Dlp;

use Pulsar\Api\Api;

use function is_array;
use function is_string;

/**
 * Filters sensitive data from log messages before they reach sinks.
 *
 * Wraps the SensitivePatternRegistry to provide a simple string-in/string-out
 * interface for log processors. When DLP is disabled, returns content unchanged.
 *
 * Compliance: PCI-DSS Req.3.4 (mask PAN when displayed), HIPAA (ePHI in logs).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class LogDlpFilter
{
    public function __construct(
        private SensitivePatternRegistry $registry,
        private DlpConfig $config,
    ) {}

    /**
     * Scan and redact sensitive data from a log message.
     */
    public function filter(string $message): string
    {
        if (!$this->config->enabled || !$this->config->scanLogs) {
            return $message;
        }

        $result = $this->registry->scan($message);

        return $result->redactedContent;
    }

    /**
     * Scan and redact sensitive data from log context arrays.
     *
     * Recursively processes string values in the context array.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function filterContext(array $context): array
    {
        if (!$this->config->enabled || !$this->config->scanLogs) {
            return $context;
        }

        return $this->filterArray($context);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function filterArray(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $result[$key] = $this->registry->scan($value)->redactedContent;
            } elseif (is_array($value)) {
                /** @var array<string, mixed> $value */
                $result[$key] = $this->filterArray($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
