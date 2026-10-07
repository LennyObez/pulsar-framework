<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Contrast;

use Pulsar\Api\Api;

use function count;

/**
 * Aggregated report of contrast ratio checks across design token pairs.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ContrastReport
{
    public int $totalPairs;
    public int $passingAa;
    public int $failingAa;

    /**
     * @param list<ContrastResult> $results
     */
    public function __construct(
        public array $results,
    ) {
        $this->totalPairs = count($results);

        $passing = 0;

        foreach ($results as $result) {
            if ($result->passesAaNormal) {
                $passing++;
            }
        }

        $this->passingAa = $passing;
        $this->failingAa = $this->totalPairs - $passing;
    }

    /**
     * Returns results that fail the specified WCAG level.
     *
     * @param string $level One of: aa_normal, aa_large, aaa_normal, aaa_large
     *
     * @return list<ContrastResult>
     */
    public function failures(string $level = 'aa_normal'): array
    {
        return array_values(array_filter(
            $this->results,
            static fn(ContrastResult $r): bool => !self::passesLevel($r, $level),
        ));
    }

    /**
     * Returns results that pass the specified WCAG level.
     *
     * @param string $level One of: aa_normal, aa_large, aaa_normal, aaa_large
     *
     * @return list<ContrastResult>
     */
    public function passes(string $level = 'aa_normal'): array
    {
        return array_values(array_filter(
            $this->results,
            static fn(ContrastResult $r): bool => self::passesLevel($r, $level),
        ));
    }

    /**
     * Returns a serializable array representation of the report.
     *
     * @return array{
     *     total_pairs: int,
     *     passing_aa: int,
     *     failing_aa: int,
     *     results: list<array{
     *         foreground: string,
     *         background: string,
     *         foreground_token: string,
     *         background_token: string,
     *         ratio: float,
     *         passes_aa_normal: bool,
     *         passes_aa_large: bool,
     *         passes_aaa_normal: bool,
     *         passes_aaa_large: bool,
     *     }>,
     * }
     */
    public function toArray(): array
    {
        return [
            'total_pairs' => $this->totalPairs,
            'passing_aa' => $this->passingAa,
            'failing_aa' => $this->failingAa,
            'results' => array_map(
                static fn(ContrastResult $r): array => [
                    'foreground' => $r->foreground->toHex(),
                    'background' => $r->background->toHex(),
                    'foreground_token' => $r->foregroundToken,
                    'background_token' => $r->backgroundToken,
                    'ratio' => round($r->ratio, 2),
                    'passes_aa_normal' => $r->passesAaNormal,
                    'passes_aa_large' => $r->passesAaLarge,
                    'passes_aaa_normal' => $r->passesAaaNormal,
                    'passes_aaa_large' => $r->passesAaaLarge,
                ],
                $this->results,
            ),
        ];
    }

    private static function passesLevel(ContrastResult $result, string $level): bool
    {
        return match ($level) {
            'aa_large' => $result->passesAaLarge,
            'aaa_normal' => $result->passesAaaNormal,
            'aaa_large' => $result->passesAaaLarge,
            default => $result->passesAaNormal,
        };
    }
}
