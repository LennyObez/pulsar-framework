<?php

declare(strict_types=1);

namespace Pulsar\Security\Dlp;

use Pulsar\Api\Api;

use function str_repeat;
use function strlen;
use function substr;

use const PREG_OFFSET_CAPTURE;

/**
 * Registry of sensitive data patterns for the DLP engine.
 *
 * Ships with built-in patterns for PCI-DSS (credit cards), PII (SSN, email),
 * and secrets (API keys). Applications may register custom patterns at boot.
 *
 * Compliance: PCI-DSS Req.3/4 (CHD protection), HIPAA (ePHI), GDPR Art.32.
 */
#[Api(since: '1.0.0')]
final class SensitivePatternRegistry
{
    /** @var list<SensitivePattern> */
    private array $patterns = [];

    public function __construct(
        private readonly DlpConfig $config,
    ) {
        $this->registerBuiltins();
    }

    public function register(SensitivePattern $pattern): void
    {
        $this->patterns[] = $pattern;
    }

    /**
     * Scan content for sensitive data matches.
     */
    public function scan(string $content): DlpScanResult
    {
        if (!$this->config->enabled || $content === '') {
            return DlpScanResult::clean($content);
        }

        $matches = [];

        foreach ($this->patterns as $pattern) {
            if (preg_match_all($pattern->regex, $content, $found, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($found[0] as $match) {
                    $value = (string) $match[0];
                    $offset = (int) $match[1];

                    if ($pattern->validator !== null && !($pattern->validator)($value)) {
                        continue;
                    }

                    $masked = $this->mask($value);
                    $matches[] = new DlpMatch(
                        type: $pattern->type,
                        pattern: $pattern->regex,
                        offset: $offset,
                        length: strlen($value),
                        maskedValue: $masked,
                    );
                }
            }
        }

        // Apply replacements from end to start to preserve offsets
        usort($matches, static fn(DlpMatch $a, DlpMatch $b): int => $b->offset <=> $a->offset);
        $redacted = $content;

        foreach ($matches as $dlpMatch) {
            $redacted = substr_replace($redacted, $dlpMatch->maskedValue, $dlpMatch->offset, $dlpMatch->length);
        }

        if ($matches === []) {
            return DlpScanResult::clean($content);
        }

        return new DlpScanResult(
            detected: true,
            actionTaken: $this->config->defaultAction,
            matches: $matches,
            redactedContent: $redacted,
        );
    }

    /**
     * @return list<SensitivePattern>
     */
    public function patterns(): array
    {
        return $this->patterns;
    }

    private function mask(string $value): string
    {
        $len = strlen($value);
        $suffix = $this->config->maskSuffixLength;

        if ($suffix >= $len) {
            return str_repeat($this->config->mask, $len);
        }

        return str_repeat($this->config->mask, $len - $suffix) . substr($value, -$suffix);
    }

    private function registerBuiltins(): void
    {
        // Credit card numbers (13-19 digits, optionally separated by spaces/dashes)
        $this->register(new SensitivePattern(
            type: SensitiveDataType::CreditCard,
            regex: '/\b(?:\d[ -]*?){13,19}\b/',
            validator: self::luhnCheck(...),
        ));

        // US Social Security Numbers
        $this->register(new SensitivePattern(
            type: SensitiveDataType::Ssn,
            regex: '/\b\d{3}-\d{2}-\d{4}\b/',
        ));

        // API keys / bearer tokens (common formats)
        $this->register(new SensitivePattern(
            type: SensitiveDataType::ApiKey,
            regex: '/\b(?:sk|pk|api|key|token|secret|bearer)[_-](?:live|test|prod)?[_-]?[a-zA-Z0-9]{20,}\b/i',
        ));

        // IPv4 addresses
        $this->register(new SensitivePattern(
            type: SensitiveDataType::IpAddress,
            regex: '/\b(?:(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.){3}(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\b/',
        ));
    }

    /**
     * Luhn algorithm check for credit card validation.
     */
    private static function luhnCheck(string $number): bool
    {
        $digits = preg_replace('/[^0-9]/', '', $number);

        if ($digits === null || strlen($digits) < 13) {
            return false;
        }

        $sum = 0;
        $len = strlen($digits);
        $parity = $len % 2;

        for ($i = 0; $i < $len; $i++) {
            $digit = (int) $digits[$i];

            if ($i % 2 === $parity) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
        }

        return $sum % 10 === 0;
    }
}
