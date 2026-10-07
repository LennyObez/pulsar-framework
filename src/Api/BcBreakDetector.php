<?php

declare(strict_types=1);

namespace Pulsar\Api;

use function array_diff;
use function array_diff_key;
use function implode;
use function is_array;
use function is_string;

/**
 * Automated backward-compatibility break detection.
 *
 * Compares two API snapshots — the exact documents
 * `tools/api/generate-snapshot.php` writes — and reports removed types, removed
 * methods, changed signatures and removed constants on the stable surface.
 *
 * It used to read a shape nothing produced. It treated the snapshot's top level
 * as the map of types, so a real snapshot presented it with exactly two
 * "classes", `api_classes` and `internal_classes`; it looked for members under a
 * `methods` key, which a real entry fills with the list of individually
 * `#[Api]`-marked method *names*, while the signatures it needed sit under
 * `signatures`; and it read a per-entry `stability` string the builder never
 * wrote. Fed the committed snapshots of two releases it returned an empty list
 * for every input — the guard on the whole `#[Api]` promise, reporting all
 * clear because it could not see the document it was handed.
 *
 * The unit tests passed throughout, because they were written against the same
 * imagined shape. That is the failure mode this class now exists to refute, so
 * its tests drive it with the real committed snapshot.
 * @api
 */
#[Api(since: '1.0.0')]
final class BcBreakDetector
{
    /**
     * Compare two snapshots.
     *
     * @param array<string, mixed> $previous Previous release snapshot
     * @param array<string, mixed> $current Current snapshot
     * @return list<BcBreak>
     */
    public function detect(array $previous, array $current): array
    {
        $breaks = [];

        $prevClasses = $this->extractClasses($previous);
        $currClasses = $this->extractClasses($current);

        foreach (array_diff_key($prevClasses, $currClasses) as $className => $classData) {
            $stability = $this->getStability($classData);
            $breaks[] = new BcBreak(
                type: BcBreakType::ClassRemoved,
                symbol: $className,
                message: "Class $className was removed",
                severity: $this->severityFor($stability),
                stability: $stability,
            );
        }

        foreach ($prevClasses as $className => $classData) {
            if (!isset($currClasses[$className])) {
                continue;
            }

            $stability = $this->getStability($classData);
            $prevMethods = $this->extractMethods($classData);
            $currMethods = $this->extractMethods($currClasses[$className]);

            foreach (array_diff_key($prevMethods, $currMethods) as $methodName => $_) {
                $breaks[] = new BcBreak(
                    type: BcBreakType::MethodRemoved,
                    symbol: "$className::$methodName",
                    message: "Method $className::$methodName was removed",
                    severity: $this->severityFor($stability),
                    stability: $stability,
                );
            }

            foreach ($prevMethods as $methodName => $methodData) {
                if (!isset($currMethods[$methodName])) {
                    continue;
                }

                $prevSig = $this->getSignature($methodData);
                $currSig = $this->getSignature($currMethods[$methodName]);

                if ($prevSig === $currSig) {
                    continue;
                }

                $breaks[] = new BcBreak(
                    type: $this->signatureBreakType($methodData, $currMethods[$methodName]),
                    symbol: "$className::$methodName",
                    message: "Signature of $className::$methodName changed from [$prevSig] to [$currSig]",
                    severity: $this->severityFor($stability),
                    stability: $stability,
                );
            }

            foreach (array_diff($this->extractConstants($classData), $this->extractConstants($currClasses[$className])) as $constantName) {
                $breaks[] = new BcBreak(
                    type: BcBreakType::ConstantRemoved,
                    symbol: "$className::$constantName",
                    message: "Constant $className::$constantName was removed",
                    severity: $this->severityFor($stability),
                    stability: $stability,
                );
            }
        }

        return $breaks;
    }

    /**
     * @param list<BcBreak> $breaks
     */
    public function hasBlockingBreaks(array $breaks): bool
    {
        foreach ($breaks as $break) {
            if ($break->severity === BcBreakSeverity::Error) {
                return true;
            }
        }

        return false;
    }

    /**
     * The stable surface of a snapshot: its `api_classes` map.
     *
     * `internal_classes` is deliberately not read. It is a flat list of names
     * with no members, it carries no promise, and treating it as a second map of
     * types is precisely how the old implementation ended up reporting the two
     * top-level keys as the only two classes in the document.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, array<string, mixed>>
     */
    private function extractClasses(array $snapshot): array
    {
        /** @var mixed $apiClasses */
        $apiClasses = $snapshot['api_classes'] ?? null;

        if (!is_array($apiClasses)) {
            return [];
        }

        $classes = [];

        /** @var mixed $entry */
        foreach ($apiClasses as $name => $entry) {
            if (is_string($name) && is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $classes[$name] = $entry;
            }
        }

        return $classes;
    }

    /**
     * The callable surface of one entry, keyed by method name.
     *
     * Read from `signatures`, which is where the builder records every public
     * method callable on the type, inherited ones included. The sibling
     * `methods` key holds only the names of members individually marked
     * `#[Api]` — a strict subset, with no parameter or return information —
     * and reading it left the detector with no signature to compare.
     *
     * @return array<string, array<string, mixed>>
     */
    private function extractMethods(mixed $classData): array
    {
        if (!is_array($classData)) {
            return [];
        }

        /** @var mixed $signatures */
        $signatures = $classData['signatures'] ?? [];

        if (!is_array($signatures)) {
            return [];
        }

        $result = [];

        /** @var mixed $signature */
        foreach ($signatures as $methodName => $signature) {
            if (is_string($methodName) && is_array($signature)) {
                /** @var array<string, mixed> $signature */
                $result[$methodName] = $signature;
            }
        }

        return $result;
    }

    /**
     * The `#[Api]`-marked constants of one entry.
     *
     * @return list<string>
     */
    private function extractConstants(mixed $classData): array
    {
        if (!is_array($classData)) {
            return [];
        }

        /** @var mixed $constants */
        $constants = $classData['constants'] ?? [];

        if (!is_array($constants)) {
            return [];
        }

        $names = [];

        /** @var mixed $constant */
        foreach ($constants as $constant) {
            if (is_string($constant)) {
                $names[] = $constant;
            }
        }

        return $names;
    }

    /**
     * The grade an entry carries, defaulting to the attribute's own default.
     *
     * The builder records `stability` only when it departs from `stable`, so an
     * absent key means stable — the same reading `#[Api]` gives it.
     */
    private function getStability(mixed $data): string
    {
        if (!is_array($data)) {
            return 'stable';
        }

        /** @var mixed $stability */
        $stability = $data['stability'] ?? 'stable';

        return is_string($stability) ? $stability : 'stable';
    }

    /**
     * One comparable line per method, rendered from the snapshot's own fields.
     *
     * `static` is part of it: dropping or adding it changes how every caller has
     * to reach the method, and comparing parameters alone would call that no
     * change at all. `inherited_from` is not — where a method is declared is
     * information for the reader of a diff, not a promise to a caller, and a
     * method moved from a parent into the child keeps working unchanged.
     */
    private function getSignature(mixed $data): string
    {
        if (!is_array($data)) {
            return '';
        }

        $params = [];

        /** @var mixed $rawParams */
        $rawParams = $data['params'] ?? [];

        if (is_array($rawParams)) {
            /** @var mixed $param */
            foreach ($rawParams as $param) {
                if (is_string($param)) {
                    $params[] = $param;
                }
            }
        }

        /** @var mixed $return */
        $return = $data['return'] ?? null;
        /** @var mixed $static */
        $static = $data['static'] ?? false;

        return ($static === true ? 'static ' : '')
            . '(' . implode(', ', $params) . ')'
            . ': ' . (is_string($return) ? $return : 'mixed');
    }

    /**
     * Distinguish a return-type change from a parameter change.
     *
     * Both are breaks, and both block; the type says which so a maintainer
     * reading the report knows whether to look at the call sites or at what the
     * callers do with the result.
     */
    private function signatureBreakType(mixed $previous, mixed $current): BcBreakType
    {
        if (!is_array($previous) || !is_array($current)) {
            return BcBreakType::SignatureChanged;
        }

        /** @var mixed $prevReturn */
        $prevReturn = $previous['return'] ?? null;
        /** @var mixed $currReturn */
        $currReturn = $current['return'] ?? null;

        if ($prevReturn === $currReturn) {
            return BcBreakType::SignatureChanged;
        }

        $onlyReturnMoved = $this->parameterList($previous) === $this->parameterList($current)
            && $this->isStatic($previous) === $this->isStatic($current);

        return $onlyReturnMoved ? BcBreakType::ReturnTypeNarrowed : BcBreakType::SignatureChanged;
    }

    /**
     * Whether one signature entry says the method is static.
     *
     * Anything that is not literally `true` reads as an instance method: the
     * builder writes a bool, and a snapshot that has been hand-edited into some
     * other shape has said nothing about staticness worth acting on.
     */
    private function isStatic(mixed $data): bool
    {
        return is_array($data) && ($data['static'] ?? false) === true;
    }

    /**
     * The parameter strings of one signature entry, whatever shape it arrived in.
     *
     * Takes `mixed` like every other reader here: the snapshot is JSON decoded
     * from disk, so nothing about it is guaranteed until this class checks it,
     * and a helper that demanded a narrower type would only move the check to its
     * callers.
     *
     * @return list<string>
     */
    private function parameterList(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }

        /** @var mixed $rawParams */
        $rawParams = $data['params'] ?? [];

        if (!is_array($rawParams)) {
            return [];
        }

        $params = [];

        /** @var mixed $param */
        foreach ($rawParams as $param) {
            if (is_string($param)) {
                $params[] = $param;
            }
        }

        return $params;
    }

    /**
     * A break on the stable surface blocks; an experimental one warns.
     */
    private function severityFor(string $stability): BcBreakSeverity
    {
        return $stability === 'stable' ? BcBreakSeverity::Error : BcBreakSeverity::Warning;
    }
}
