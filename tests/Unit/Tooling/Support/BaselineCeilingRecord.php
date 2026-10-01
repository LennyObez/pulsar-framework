<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling\Support;

use JsonException;
use RuntimeException;

use function array_key_exists;
use function file_get_contents;
use function is_array;
use function is_file;
use function is_int;
use function is_string;
use function json_decode;
use function preg_match;
use function sprintf;
use function strlen;

use const JSON_THROW_ON_ERROR;

/**
 * Reads what a ceiling file says about the numbers it holds, and refuses a number
 * nothing in the file argues for.
 *
 * WHY THIS EXISTS
 *
 * `tools/php/analysis-baseline-ceiling.json` was built so that a suppression count could
 * not grow without a visible, deliberate line in a diff. It did not do that. Raising
 * `findings` from 1286 to 1295 — nine findings buried — while leaving `why` and
 * `raisedFrom` exactly as they were passed the ratchet with exit 0, because the only
 * thing anything compared was the measured count against the recorded one, and the
 * recorded one is whatever the last editor typed. Observed on 2026-09-02 against the
 * unmodified file: seven tests, 126 assertions, green.
 *
 * That is the shape ADR-0060 is about. The guard existed, was cited in two briefs and a
 * governance page as the thing that made growth deliberate, and had never been observed
 * to refuse anything.
 *
 * HOW A NUMBER IS BOUND TO ITS REASON, WITHOUT CONSULTING HISTORY
 *
 * The reason must name the number. `why` has to contain the current `findings` value as
 * a standalone integer, and the previous one when the entry records a raise. So the
 * number cannot move on its own: change `findings` and the prose no longer cites it, and
 * the only way back to green is to write down what the new number is and why it is the
 * new number. The check is file-local on purpose — it holds in a shallow clone, in a
 * worktree, and in a tree whose ceiling file is not yet committed, none of which a
 * comparison against `git show HEAD:` survives.
 *
 * It cannot tell a rewritten reason from a good one. Nothing mechanical can. What it can
 * do is make the rewrite happen, in the same commit, in the diff a reviewer reads.
 */
final class BaselineCeilingRecord
{
    /**
     * The shortest `why` that could carry an argument rather than a shrug.
     *
     * "measured" is not a reason; neither is "raised to make the gate pass". The bound
     * is deliberately low — a reviewer judges the prose, this only refuses its absence.
     */
    private const int MINIMUM_REASON = 120;

    /**
     * Every ceiling in the file whose number the file does not account for.
     *
     * @return list<string> one complaint per unjustified number, empty when every
     *                      ceiling names what it holds and why
     *
     * @throws RuntimeException when the document cannot be read or is not a ceiling
     *                          file at all — never an empty list, which would report an
     *                          unreadable ratchet as a satisfied one
     */
    public static function unjustifiedCeilings(string $path): array
    {
        $complaints = [];

        foreach (self::ceilingEntries($path) as $baseline => $entry) {
            foreach (self::complaintsAbout($baseline, $entry) as $complaint) {
                $complaints[] = $complaint;
            }
        }

        return $complaints;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return list<string>
     */
    private static function complaintsAbout(string $baseline, array $entry): array
    {
        $findings = $entry['findings'] ?? null;

        if (!is_int($findings)) {
            return [sprintf('%s has no integer `findings`, so it ratchets nothing.', $baseline)];
        }

        $complaints = [];
        $why = $entry['why'] ?? null;

        if (!is_string($why) || strlen($why) < self::MINIMUM_REASON) {
            $complaints[] = sprintf(
                '%s records a ceiling of %d with no `why` worth the name. The number is the whole '
                . 'claim: %d findings that reach nobody. Say which findings they are and what makes '
                . 'recording them the right answer, in at least %d characters.',
                $baseline,
                $findings,
                $findings,
                self::MINIMUM_REASON,
            );

            // Every remaining rule is about what the reason says. There is no reason.
            return $complaints;
        }

        if (!self::cites($why, $findings)) {
            $complaints[] = sprintf(
                "%s holds a ceiling of %d and its `why` never mentions %d.\n\n"
                . "A reason that does not name the number it justifies survives any change to that\n"
                . "number: raise the ceiling, leave the prose, and the diff shows one digit moving with\n"
                . "an unchanged paragraph beside it claiming to explain it. Write the current figure\n"
                . 'into `why`, with what it counts.',
                $baseline,
                $findings,
                $findings,
            );
        }

        if (!array_key_exists('raisedFrom', $entry)) {
            $complaints[] = sprintf(
                '%s does not record a `raisedFrom`. Give it the ceiling this number replaced, or '
                . 'null when %d is the first figure ever written down for it — the difference is how '
                . 'much was buried, and a reader cannot recover it from one number.',
                $baseline,
                $findings,
            );

            return $complaints;
        }

        $raisedFrom = $entry['raisedFrom'];

        if ($raisedFrom !== null) {
            if (!is_int($raisedFrom)) {
                $complaints[] = sprintf('%s has a non-integer `raisedFrom`.', $baseline);
            } elseif ($raisedFrom === $findings) {
                $complaints[] = sprintf(
                    '%s says it was raised from %d to %d, which is not a raise. Either the number '
                    . 'moved and the record did not, or the record is stale — both leave the file '
                    . 'asserting something that did not happen.',
                    $baseline,
                    $raisedFrom,
                    $findings,
                );
            } elseif (!self::cites($why, $raisedFrom)) {
                $complaints[] = sprintf(
                    '%s moved from %d to %d and its `why` never mentions %d, so the reason predates '
                    . 'the move it is supposed to explain.',
                    $baseline,
                    $raisedFrom,
                    $findings,
                    $raisedFrom,
                );
            }

            $raisedOn = $entry['raisedOn'] ?? null;

            if (!is_string($raisedOn) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $raisedOn) !== 1) {
                $complaints[] = sprintf(
                    '%s records a raise with no `raisedOn` date in YYYY-MM-DD form. When a ceiling '
                    . 'moved is half of whether the measurement behind it still holds.',
                    $baseline,
                );
            }
        }

        return $complaints;
    }

    /**
     * Does the prose name this exact figure?
     *
     * Bounded by digits only, so that 129 does not match inside 1295 and 1295 does not
     * match inside 12950 — but so that a figure at the end of a sentence still counts.
     * Excluding a following full stop would refuse "the ceiling is 1292." and teach the
     * next author that the rule is arbitrary, which is how a rule stops being read.
     */
    private static function cites(string $why, int $number): bool
    {
        return preg_match(sprintf('/(?<![0-9])%d(?![0-9])/', $number), $why) === 1;
    }

    /**
     * @return array<string, array<string, mixed>>
     *
     * @throws RuntimeException
     */
    private static function ceilingEntries(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('No ceiling file at %s', $path));
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Unreadable ceiling file at %s', $path));
        }

        try {
            /** @var mixed $document */
            $document = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf('%s is not valid JSON: %s', $path, $exception->getMessage()));
        }

        if (!is_array($document) || !isset($document['ceilings']) || !is_array($document['ceilings'])) {
            throw new RuntimeException(sprintf(
                '%s has no `ceilings` map, so there is nothing to hold anything to. An empty result '
                . 'here would read as a file whose every ceiling is justified.',
                $path,
            ));
        }

        $entries = [];

        /** @var mixed $entry */
        foreach ($document['ceilings'] as $baseline => $entry) {
            if (!is_string($baseline) || !is_array($entry)) {
                throw new RuntimeException(sprintf('%s holds a ceiling that is not a named object.', $path));
            }

            /** @var array<string, mixed> $entry */
            $entries[$baseline] = $entry;
        }

        if ($entries === []) {
            throw new RuntimeException(sprintf(
                '%s holds no ceilings at all, so this reports nothing about every baseline in the '
                . 'repository.',
                $path,
            ));
        }

        return $entries;
    }
}
