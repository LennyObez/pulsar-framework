<?php

declare(strict_types=1);

namespace Pulsar\Api;

use function array_diff_key;
use function is_array;
use function is_string;

/**
 * Automated backward-compatibility break detection.
 *
 * Compares two API snapshots to detect removed classes, removed methods,
 * changed signatures, and narrowed return types on stable APIs.
 * @api
 */
#[Api(since: '1.0.0')]
final class BcBreakDetector
{
    /**
     * @param array<string, mixed> $previous Previous release snapshot
     * @param array<string, mixed> $current Current snapshot
     * @return list<BcBreak>
     */
    public function detect(array $previous, array $current): array
    {
        $breaks = [];

        $prevClasses = $this->extractClasses($previous);
        $currClasses = $this->extractClasses($current);

        // Detect removed classes
        $removedClasses = array_diff_key($prevClasses, $currClasses);

        foreach ($removedClasses as $className => $classData) {
            $stability = $this->getStability($classData);
            $breaks[] = new BcBreak(
                type: BcBreakType::ClassRemoved,
                symbol: $className,
                message: "Class $className was removed",
                severity: $stability === 'stable' ? BcBreakSeverity::Error : BcBreakSeverity::Warning,
                stability: $stability,
            );
        }

        // Detect removed methods in existing classes
        foreach ($prevClasses as $className => $classData) {
            if (!isset($currClasses[$className])) {
                continue;
            }

            $prevMethods = $this->extractMethods($classData);
            $currMethods = $this->extractMethods($currClasses[$className]);

            $removedMethods = array_diff_key($prevMethods, $currMethods);

            foreach ($removedMethods as $methodName => $methodData) {
                $stability = $this->getStability($methodData);
                $breaks[] = new BcBreak(
                    type: BcBreakType::MethodRemoved,
                    symbol: "$className::$methodName",
                    message: "Method $className::$methodName was removed",
                    severity: $stability === 'stable' ? BcBreakSeverity::Error : BcBreakSeverity::Warning,
                    stability: $stability,
                );
            }

            // Detect changed signatures
            foreach ($prevMethods as $methodName => $methodData) {
                if (!isset($currMethods[$methodName])) {
                    continue;
                }

                $prevSig = $this->getSignature($methodData);
                $currSig = $this->getSignature($currMethods[$methodName]);

                if ($prevSig !== '' && $currSig !== '' && $prevSig !== $currSig) {
                    $stability = $this->getStability($methodData);
                    $breaks[] = new BcBreak(
                        type: BcBreakType::SignatureChanged,
                        symbol: "$className::$methodName",
                        message: "Signature of $className::$methodName changed from [$prevSig] to [$currSig]",
                        severity: $stability === 'stable' ? BcBreakSeverity::Error : BcBreakSeverity::Warning,
                        stability: $stability,
                    );
                }
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
     * @param array<string, mixed> $snapshot
     * @return array<string, array<string, mixed>>
     */
    private function extractClasses(array $snapshot): array
    {
        $classes = [];

        /** @var mixed $value */
        foreach ($snapshot as $key => $value) {
            if (is_string($key) && is_array($value)) {
                /** @var array<string, mixed> $value */
                $classes[$key] = $value;
            }
        }

        return $classes;
    }

    /**
     * @param mixed $classData
     * @return array<string, array<string, mixed>>
     */
    private function extractMethods(mixed $classData): array
    {
        if (!is_array($classData)) {
            return [];
        }

        /** @var mixed $methods */
        $methods = $classData['methods'] ?? [];

        if (!is_array($methods)) {
            return [];
        }

        $result = [];

        /** @var mixed $methodData */
        foreach ($methods as $methodName => $methodData) {
            if (is_string($methodName) && is_array($methodData)) {
                /** @var array<string, mixed> $methodData */
                $result[$methodName] = $methodData;
            }
        }

        return $result;
    }

    private function getStability(mixed $data): string
    {
        if (is_array($data)) {
            /** @var mixed $stability */
            $stability = $data['stability'] ?? 'stable';

            return is_string($stability) ? $stability : 'stable';
        }

        return 'stable';
    }

    private function getSignature(mixed $data): string
    {
        if (is_array($data)) {
            /** @var mixed $sig */
            $sig = $data['signature'] ?? '';

            return is_string($sig) ? $sig : '';
        }

        return '';
    }
}
