<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Backup;

use NoDiscard;
use Override;
use Pulsar\Api\Api;

use function array_key_exists;
use function str_starts_with;
use function strlen;

/**
 * A restore target that keeps what comes back in memory instead of writing it.
 *
 * NOT A DEVELOPMENT STUB, and it is the one class here where saying so matters.
 * Its purpose is the restore drill: something has to receive the entries of a
 * round trip so the bytes that came out can be compared with the bytes that went
 * in, and a target that wrote to the live database would make the drill the
 * disaster it is rehearsing for. The compliance observer in
 * `src/Compliance/Evidence/` uses exactly this, which is what lets the recovery
 * control be decided by a real backup, a real seal and a real restore against the
 * booted application while changing nothing the application owns.
 *
 * The bound is stated because it is the reason this is not the general answer:
 * everything restored through it is held whole in process memory, so it belongs
 * to drills over synthetic payloads and to tests, never to a recovery.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final class InMemoryRestoreTarget implements RestoreTargetInterface
{
    /** @var array<string, string> */
    private array $received = [];

    /**
     * @param non-empty-string $id     Reported per entry in the restore report
     * @param string|null      $prefix Only entries under this prefix are claimed; null claims all
     */
    public function __construct(
        private readonly string $id = 'memory',
        private readonly ?string $prefix = null,
    ) {}

    #[Override]
    public function id(): string
    {
        return $this->id;
    }

    #[Override]
    public function accepts(string $entryName): bool
    {
        return $this->prefix === null || str_starts_with($entryName, $this->prefix);
    }

    /**
     * @param iterable<string> $chunks
     *
     * @return int<0, max>
     */
    #[Override]
    public function restore(string $entryName, iterable $chunks): int
    {
        $content = '';

        foreach ($chunks as $chunk) {
            $content .= $chunk;
        }

        $this->received[$entryName] = $content;

        return strlen($content);
    }

    /**
     * What came back for one entry, or null if the archive held no such entry.
     */
    #[NoDiscard]
    public function contentOf(string $entryName): ?string
    {
        return array_key_exists($entryName, $this->received) ? $this->received[$entryName] : null;
    }

    /**
     * @return array<string, string>
     */
    #[NoDiscard]
    public function all(): array
    {
        return $this->received;
    }
}
