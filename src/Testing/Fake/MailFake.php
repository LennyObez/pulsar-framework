<?php

declare(strict_types=1);

namespace Pulsar\Testing\Fake;

use Closure;
use PHPUnit\Framework\Assert;
use Pulsar\Api\Api;
use Pulsar\Mail\Address;
use Pulsar\Mail\Mailable;
use Pulsar\Mail\MailManagerInterface;
use Pulsar\Mail\Message;
use Pulsar\Mail\TransportInterface;

use function array_filter;
use function array_values;
use function bin2hex;
use function count;
use function get_class;
use function implode;
use function in_array;
use function random_bytes;
use function sprintf;

/**
 * Fake mail manager that records all sent mail for assertion.
 *
 * Captures both Mailable sends and raw Message sends, enabling tests
 * to verify mail was sent to the right recipients with the right content.
 * @api
 */
#[Api(since: '1.0.0')]
final class MailFake implements MailManagerInterface
{
    /** @var list<Mailable> */
    private array $sentMailables = [];

    /** @var list<Message> */
    private array $sentMessages = [];

    private readonly Address $defaultFrom;

    public function __construct(?Address $defaultFrom = null)
    {
        $this->defaultFrom = $defaultFrom ?? new Address('test@example.com', 'Test');
    }

    public function send(Mailable $mailable): string
    {
        $this->sentMailables[] = $mailable;
        $message = $mailable->build($this->defaultFrom);
        $this->sentMessages[] = $message;

        return sprintf('<fake-%s@localhost>', bin2hex(random_bytes(16)));
    }

    public function driver(?string $name = null): TransportInterface
    {
        return new class implements TransportInterface {
            public function send(Message $message): string
            {
                return '<noop@localhost>';
            }

            public function name(): string
            {
                return 'fake';
            }
        };
    }

    public function raw(Message $message): string
    {
        $this->sentMessages[] = $message;

        return sprintf('<fake-%s@localhost>', bin2hex(random_bytes(16)));
    }

    /**
     * Assert that a mailable of the given class was sent.
     *
     * @param class-string<Mailable> $mailableClass
     * @param int|null $count Exact number expected (null = at least one)
     */
    public function assertSent(string $mailableClass, ?int $count = null): void
    {
        $matching = $this->mailablesOfType($mailableClass);
        $matchCount = count($matching);

        if ($count !== null) {
            Assert::assertSame(
                $count,
                $matchCount,
                sprintf(
                    "Expected %d send(s) of [%s], but %d occurred.\nSent mailables: %s",
                    $count,
                    $mailableClass,
                    $matchCount,
                    $this->formatSentList(),
                ),
            );
        } else {
            Assert::assertGreaterThan(
                0,
                $matchCount,
                sprintf(
                    "Expected mailable [%s] to be sent, but it was not.\nSent mailables: %s",
                    $mailableClass,
                    $this->formatSentList(),
                ),
            );
        }
    }

    /**
     * Assert that a mailable was sent to a specific email address.
     *
     * @param class-string<Mailable> $mailableClass
     */
    public function assertSentTo(string $email, string $mailableClass): void
    {
        $matching = array_filter(
            $this->mailablesOfType($mailableClass),
            fn(Mailable $m): bool => $this->messageHasRecipient(
                $m->build($this->defaultFrom),
                $email,
            ),
        );

        Assert::assertNotEmpty(
            $matching,
            sprintf(
                "Expected mailable [%s] to be sent to [%s], but it was not.\nActual recipients: %s",
                $mailableClass,
                $email,
                $this->formatRecipients($mailableClass),
            ),
        );
    }

    /**
     * Assert that a mailable matching a callback was sent.
     *
     * @param class-string<Mailable> $mailableClass
     * @param Closure(Mailable): bool $callback
     */
    public function assertSentWith(string $mailableClass, Closure $callback): void
    {
        $matching = array_filter(
            $this->mailablesOfType($mailableClass),
            $callback,
        );

        Assert::assertNotEmpty(
            $matching,
            sprintf(
                "Expected mailable [%s] matching callback to be sent, but none matched.\nTotal [%s] sent: %d",
                $mailableClass,
                $mailableClass,
                count($this->mailablesOfType($mailableClass)),
            ),
        );
    }

    /**
     * Assert that a mailable was NOT sent.
     *
     * @param class-string<Mailable> $mailableClass
     */
    public function assertNotSent(string $mailableClass): void
    {
        $matching = $this->mailablesOfType($mailableClass);

        Assert::assertCount(
            0,
            $matching,
            sprintf(
                'Expected mailable [%s] NOT to be sent, but it was sent %d time(s).',
                $mailableClass,
                count($matching),
            ),
        );
    }

    /**
     * Assert that no mail was sent at all.
     */
    public function assertNothingSent(): void
    {
        Assert::assertSame(
            count($this->sentMailables),
            0,
            sprintf(
                "Expected no mail to be sent, but %d mailable(s) were sent.\nSent: %s",
                count($this->sentMailables),
                $this->formatSentList(),
            ),
        );
    }

    /**
     * Get all sent mailables.
     *
     * @return list<Mailable>
     */
    public function sentMailables(): array
    {
        return $this->sentMailables;
    }

    /**
     * Get all sent messages (built from mailables + raw).
     *
     * @return list<Message>
     */
    public function sentMessages(): array
    {
        return $this->sentMessages;
    }

    /**
     * Get all sent mailables of a specific type.
     *
     * @param class-string<Mailable> $mailableClass
     *
     * @return list<Mailable>
     */
    public function mailablesOfType(string $mailableClass): array
    {
        return array_values(array_filter(
            $this->sentMailables,
            static fn(Mailable $m): bool => $m instanceof $mailableClass,
        ));
    }

    /**
     * Reset all recorded state.
     */
    public function reset(): void
    {
        $this->sentMailables = [];
        $this->sentMessages = [];
    }

    private function messageHasRecipient(Message $message, string $email): bool
    {
        $allRecipients = [];

        foreach ($message->to as $addr) {
            $allRecipients[] = $addr->email;
        }

        foreach ($message->cc as $addr) {
            $allRecipients[] = $addr->email;
        }

        foreach ($message->bcc as $addr) {
            $allRecipients[] = $addr->email;
        }

        return in_array($email, $allRecipients, true);
    }

    private function formatSentList(): string
    {
        if ($this->sentMailables === []) {
            return '(none)';
        }

        $classes = [];

        foreach ($this->sentMailables as $mailable) {
            $class = get_class($mailable);

            if (!isset($classes[$class])) {
                $classes[$class] = 0;
            }

            ++$classes[$class];
        }

        $parts = [];

        foreach ($classes as $class => $classCount) {
            $parts[] = sprintf('%s (%dx)', $class, $classCount);
        }

        return implode(', ', $parts);
    }

    /**
     * @param class-string<Mailable> $mailableClass
     */
    private function formatRecipients(string $mailableClass): string
    {
        $recipients = [];

        foreach ($this->mailablesOfType($mailableClass) as $mailable) {
            $message = $mailable->build($this->defaultFrom);

            foreach ($message->to as $addr) {
                $recipients[] = $addr->email;
            }
        }

        return $recipients === [] ? '(none)' : implode(', ', $recipients);
    }
}
