<?php

declare(strict_types=1);

namespace Pulsar\Http\Response;

use Pulsar\Api\Internal;

/**
 * The chunks a {@see StreamedResponse} has already pulled off its source.
 *
 * A streamed source is single-pass. A Generator refuses a second traversal
 * outright — `Cannot traverse an already closed generator` — and an Iterator
 * that cannot rewind yields nothing the second time. So whoever reads the
 * source first has to keep what it gave, or the response is empty for everyone
 * after them.
 *
 * This is an object, and that is the whole point of it. `clone` copies an array
 * property but SHARES an object one, and every `with*()` call on a
 * StreamedResponse produces a clone that goes on reading the same iterator. A
 * buffer held as an array would stay behind with whichever copy filled it,
 * leaving its siblings pointed at a source that has already been consumed.
 * Held here, one buffer serves the original and every clone of it.
 *
 * Deliberately not `final`. The substitutability gate (`composer class-shape`)
 * treats a collaborator typed to a final concrete class as a seam nothing can
 * substitute, and reaches that verdict whether or not a consumer could ever
 * reach the seam. Being open costs this class nothing — {@see StreamedResponse}
 * constructs the only instance that exists, so a subclass has nowhere to enter
 * from — while being final would buy an accepted-finding entry in a baseline
 * that is ratcheted precisely so it stops growing.
 */
#[Internal(reason: 'Owned by StreamedResponse; the shared drain state of one response')]
class StreamedBodyBuffer
{
    /**
     * Every chunk the source yielded, or null while it has not been read.
     *
     * Null is the load-bearing distinction: an empty list is a source that was
     * read and yielded nothing, and re-reading THAT is what throws.
     *
     * @var list<string>|null
     */
    private ?array $chunks = null;

    /**
     * @return list<string>|null
     */
    public function chunks(): ?array
    {
        return $this->chunks;
    }

    /**
     * @param list<string> $chunks
     */
    public function store(array $chunks): void
    {
        $this->chunks = $chunks;
    }
}
