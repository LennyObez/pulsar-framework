<?php

declare(strict_types=1);

namespace Pulsar\Security\Dlp;

use Pulsar\Api\Api;

/**
 * Configuration for the Data Loss Prevention engine.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DlpConfig
{
    /**
     * @param bool      $enabled        Whether DLP scanning is active
     * @param DlpAction $defaultAction  Default action when sensitive data is found
     * @param string    $mask           Mask character used for redaction
     * @param int       $maskSuffixLength Number of trailing characters to show unmasked (e.g., "****1234")
     * @param bool      $scanLogs       Whether to scan log entries
     * @param bool      $scanResponses  Whether to scan outgoing HTTP responses
     */
    public function __construct(
        public bool $enabled = true,
        public DlpAction $defaultAction = DlpAction::Redact,
        public string $mask = '*',
        public int $maskSuffixLength = 4,
        public bool $scanLogs = true,
        public bool $scanResponses = true,
    ) {}

    /**
     * @param array{
     *     enabled?: bool,
     *     default_action?: string,
     *     mask?: string,
     *     mask_suffix_length?: int,
     *     scan_logs?: bool,
     *     scan_responses?: bool,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $action = isset($data['default_action'])
            ? DlpAction::from($data['default_action'])
            : DlpAction::Redact;

        return new self(
            enabled: $data['enabled'] ?? true,
            defaultAction: $action,
            mask: $data['mask'] ?? '*',
            maskSuffixLength: $data['mask_suffix_length'] ?? 4,
            scanLogs: $data['scan_logs'] ?? true,
            scanResponses: $data['scan_responses'] ?? true,
        );
    }
}
