<?php

declare(strict_types=1);

namespace Pulsar\Mail\Transport;

use Pulsar\Api\Internal;
use Pulsar\Mail\Message;
use Pulsar\Mail\TransportInterface;

use function bin2hex;
use function random_bytes;
use function sprintf;

/**
 * In-memory mail transport for testing.
 *
 * Stores sent messages in an array so test assertions can inspect them.
 */
#[Internal]
final class ArrayTransport implements TransportInterface
{
    /** @var list<Message> */
    private array $messages = [];

    public function send(Message $message): string
    {
        $messageId = sprintf('<array-%s@localhost>', bin2hex(random_bytes(16)));
        $this->messages[] = $message;

        return $messageId;
    }

    public function name(): string
    {
        return 'array';
    }

    /**
     * Get all sent messages (for test assertions).
     *
     * @return list<Message>
     */
    public function sent(): array
    {
        return $this->messages;
    }

    /**
     * Clear all stored messages.
     */
    public function flush(): void
    {
        $this->messages = [];
    }
}
