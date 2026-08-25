<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Evidence;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;
use Throwable;

use function sprintf;

/**
 * Thrown when a compliance evidence record could not be persisted.
 *
 * Loud on purpose. A store that swallowed the failure would leave the deployment
 * believing it holds an evidence trail it does not hold, which is the failure
 * mode ADR-0041 names: a control reporting itself implemented on the strength of
 * code existing. The engine catches this at the recording seam so a failed write
 * never discards an already-built verification report — see
 * {@see \Pulsar\Compliance\Verification\ComplianceVerificationEngine} — but it is
 * logged as an error there rather than ignored.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final class EvidenceWriteFailedException extends RuntimeException
{
    #[NoDiscard]
    public static function notWritable(string $path): self
    {
        return new self(sprintf(
            'Could not append a compliance evidence record to "%s". The evidence chain is '
                . 'only tamper-evident while every record reaches the store, so a dropped '
                . 'write is reported rather than skipped.',
            $path,
        ));
    }

    #[NoDiscard]
    public static function directoryNotCreated(string $directory): self
    {
        return new self(sprintf(
            'Could not create the compliance evidence directory "%s".',
            $directory,
        ));
    }

    #[NoDiscard]
    public static function notEncodable(string $recordId, Throwable $previous): self
    {
        return new self(
            sprintf('Compliance evidence record "%s" could not be encoded as JSON.', $recordId),
            0,
            $previous,
        );
    }

    #[NoDiscard]
    public static function headNotWritable(string $path): self
    {
        return new self(sprintf(
            'Could not write the compliance evidence anchor to "%s". The anchor is the only '
                . 'thing that makes records removed from the END of the register detectable, '
                . 'so a register whose anchor stopped being maintained is reported rather '
                . 'than left to look verifiable.',
            $path,
        ));
    }

    #[NoDiscard]
    public static function headNotEncodable(string $path, Throwable $previous): self
    {
        return new self(
            sprintf('The compliance evidence anchor for "%s" could not be encoded as JSON.', $path),
            0,
            $previous,
        );
    }
}
