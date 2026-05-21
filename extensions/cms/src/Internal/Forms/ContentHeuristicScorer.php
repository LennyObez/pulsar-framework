<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Forms;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Forms\SpamDetection\SpamDetectorInterface;
use Pulsar\Extension\Cms\Forms\SpamDetection\SpamResult;

use function is_string;
use function mb_strlen;
use function mb_strtoupper;
use function preg_match;
use function preg_match_all;
use function trim;

/**
 * Content heuristic spam scorer based on text patterns.
 *
 * Checks for: excessive URLs, repeated characters, all-caps text,
 * and empty required fields.
 *
 * @psalm-api Aggregated by SpamScorer through the SpamDetectorInterface contract;
 *            resolved from the DI container, not instantiated by name.
 */
#[Internal(reason: 'Spam detector; use SpamDetectorInterface')]
final readonly class ContentHeuristicScorer implements SpamDetectorInterface
{
    /** @var list<string> */
    private array $requiredFields;

    /**
     * @param list<string> $requiredFields
     */
    public function __construct(
        array $requiredFields = [],
    ) {
        $this->requiredFields = $requiredFields;
    }

    #[Override]
    public function detect(array $data, array $meta): SpamResult
    {
        $score = 0.0;
        $reasons = [];

        foreach ($data as $value) {
            if (!is_string($value)) {
                continue;
            }

            // Check for excessive URLs (more than 3)
            $urlCount = preg_match_all('/https?:\/\//', $value);

            if ($urlCount > 3) {
                $score += 2.0;
                $reasons[] = 'Excessive URLs (' . $urlCount . ')';
            }

            // Check for repeated characters (more than 10 of the same char)
            if (preg_match('/(.)\1{10,}/', $value) === 1) {
                $score += 1.5;
                $reasons[] = 'Repeated characters detected';
            }

            // Check for all-caps (more than 50% uppercase in strings longer than 10 chars)
            $trimmed = trim($value);

            if (mb_strlen($trimmed) > 10) {
                $upperVersion = mb_strtoupper($trimmed);

                if ($trimmed === $upperVersion) {
                    $score += 1.0;
                    $reasons[] = 'All-caps text detected';
                }
            }
        }

        // Check for empty required fields
        foreach ($this->requiredFields as $field) {
            /** @var mixed $fieldValue */
            $fieldValue = $data[$field] ?? null;

            if (!is_string($fieldValue) || trim($fieldValue) === '') {
                $score += 3.0;
                $reasons[] = 'Required field empty: ' . $field;
            }
        }

        $reason = $reasons !== [] ? implode('; ', $reasons) : null;

        return new SpamResult($score > 0.0, $score, $reason);
    }
}
