<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Internal\Authorization;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\AuthorizationDecision;
use Pulsar\Auth\Internal\Authorization\AuthorizationDecisionFlushListener;
use Pulsar\Auth\Internal\Authorization\BufferedDecisionSinkInterface;
use stdClass;

use function count;

/**
 * The drain point reaches whichever buffered sink the deployment wired.
 *
 * The listener used to name {@see \Pulsar\Auth\Internal\Authorization\BufferedAuthorizationDecisionSink},
 * which is `final`. A deployment that buffers decisions differently — per
 * database transaction, into a different store — could implement
 * {@see \Pulsar\Auth\Authorization\AuthorizationDecisionSinkInterface} and still
 * have nothing able to tell it the request was over, because the one thing that
 * announces the drain point could not be handed anything else.
 */
#[CoversClass(AuthorizationDecisionFlushListener::class)]
final class AuthorizationDecisionFlushListenerTest extends TestCase
{
    #[Test]
    public function flushesASinkThatIsNotTheShippedOne(): void
    {
        $sink = new CountingBufferedSink();
        $listener = new AuthorizationDecisionFlushListener($sink);

        $listener(new stdClass());

        self::assertSame(1, $sink->flushes);
    }

    /**
     * The event is the moment, not a payload: the listener is registered on the
     * kernel's terminate event and on two queue events, and reads none of them.
     */
    #[Test]
    public function ignoresWhateverTheDrainPointCarries(): void
    {
        $sink = new CountingBufferedSink();
        $listener = new AuthorizationDecisionFlushListener($sink);

        $listener(new stdClass());
        $listener(new CountingBufferedSink());

        self::assertSame(2, $sink->flushes);
    }

    #[Test]
    public function aSinkHoldingNothingIsStillReached(): void
    {
        $sink = new CountingBufferedSink();
        $listener = new AuthorizationDecisionFlushListener($sink);

        self::assertSame(0, $sink->buffered());

        $listener(new stdClass());

        self::assertSame(1, $sink->flushes, 'the listener decided for the sink whether flushing was worth it');
    }
}

/**
 * A buffered sink that is not the shipped one and counts what reaches it.
 */
final class CountingBufferedSink implements BufferedDecisionSinkInterface
{
    public int $flushes = 0;

    /** @var list<AuthorizationDecision> */
    private array $held = [];

    public function record(AuthorizationDecision $decision): void
    {
        $this->held[] = $decision;
    }

    public function flush(): void
    {
        ++$this->flushes;
        $this->held = [];
    }

    public function buffered(): int
    {
        return count($this->held);
    }
}
