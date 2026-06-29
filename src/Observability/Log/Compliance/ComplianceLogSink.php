<?php

declare(strict_types=1);

namespace Pulsar\Observability\Log\Compliance;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogSinkInterface;
use Pulsar\Security\Crypto\EncryptorInterface;

use function array_values;
use function error_log;
use function json_encode;
use function json_last_error_msg;

/**
 * Compliance-aware log sink that routes entries through regulation-specific
 * formatters before writing to an underlying sink.
 *
 * When an encryptor is provided, log entry content is encrypted before
 * being written. All crypto operations use the central Keyring via
 * EncryptorInterface.
 */
#[Internal(reason: 'Compliance sink implementation detail')]
final readonly class ComplianceLogSink implements LogSinkInterface
{
    /**
     * @var list<ComplianceLogFormatter>
     */
    private array $formatters;

    public function __construct(
        private LogSinkInterface $underlyingSink,
        private ?EncryptorInterface $encryptor = null,
        ComplianceLogFormatter ...$formatters,
    ) {
        $this->formatters = array_values($formatters);
    }

    #[Override]
    public function write(LogEntry $entry): void
    {
        $transformed = $entry;

        foreach ($this->formatters as $formatter) {
            $transformed = $formatter->format($transformed);
        }

        if ($this->encryptor !== null) {
            $serialized = json_encode([
                'level' => $transformed->level->value,
                'message' => $transformed->message,
                'context' => $transformed->context,
                'channel' => $transformed->channel,
                'timestamp' => $transformed->timestamp->format('Y-m-d\TH:i:s.uP'),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($serialized === false) {
                // A non-UTF-8 byte sequence in a context value makes the entry
                // unencodable. Falling back to '{}' keeps logging crash-free,
                // but for a banking/healthcare audit trail a silently dropped
                // record is undetectable — surface the loss on PHP's
                // bottom-of-stack diagnostic channel so operators can act.
                error_log(
                    '[Pulsar ComplianceLogSink] json_encode failed (' . json_last_error_msg()
                    . ') — entry may contain non-UTF-8 context; logging empty record',
                );

                $serialized = '{}';
            }

            $encrypted = $this->encryptor->encrypt($serialized);

            $transformed = new LogEntry(
                level: $transformed->level,
                message: $encrypted,
                context: ['encrypted' => true],
                channel: $transformed->channel,
                timestamp: $transformed->timestamp,
            );
        }

        $this->underlyingSink->write($transformed);
    }
}
