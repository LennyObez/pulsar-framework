<?php

declare(strict_types=1);

namespace Pulsar\Dev\Mail;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Mail\Message;
use Pulsar\Mail\TransportInterface;

use function bin2hex;
use function count;
use function microtime;
use function random_bytes;
use function sprintf;

/**
 * Dev-mode mail transport that intercepts all outgoing emails and stores
 * them in memory for the mail preview UI.
 *
 * In production, this transport must never be configured. It does not
 * send any real emails: all messages are captured for local inspection.
 */
#[Internal]
final class MailCaptureDriver implements TransportInterface
{
    /** @var list<CapturedMessage> */
    private array $messages = [];

    #[Override]
    public function send(Message $message): string
    {
        $id = bin2hex(random_bytes(16));
        $messageId = sprintf('<capture-%s@localhost>', $id);

        $this->messages[] = new CapturedMessage(
            id: $id,
            message: $message,
            capturedAt: microtime(true),
        );

        return $messageId;
    }

    #[Override]
    public function name(): string
    {
        return 'capture';
    }

    /**
     * Get all captured messages, newest first.
     *
     * @return list<CapturedMessage>
     */
    public function all(): array
    {
        return array_reverse($this->messages);
    }

    /**
     * Find a captured message by its ID.
     */
    public function find(string $id): ?CapturedMessage
    {
        foreach ($this->messages as $captured) {
            if ($captured->id === $id) {
                return $captured;
            }
        }

        return null;
    }

    /**
     * Get the total number of captured messages.
     */
    public function count(): int
    {
        return count($this->messages);
    }

    /**
     * Clear all captured messages.
     */
    public function flush(): void
    {
        $this->messages = [];
    }

    /**
     * Get the most recently captured message.
     */
    public function latest(): ?CapturedMessage
    {
        if ($this->messages === []) {
            return null;
        }

        return $this->messages[count($this->messages) - 1];
    }
}
