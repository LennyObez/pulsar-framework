<?php

declare(strict_types=1);

namespace Pulsar\Tests\Runner;

use Override;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\SkippedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

use function bin2hex;
use function file_put_contents;
use function getenv;
use function getmypid;
use function is_dir;
use function json_encode;
use function random_bytes;
use function rtrim;
use function str_replace;
use function trim;

use const FILE_APPEND;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const LOCK_EX;
use const PHP_EOL;

/**
 * Records why each skipped test skipped, because nothing else does.
 *
 * A suite reports green in two very different situations: everything ran, or a great
 * deal of it quietly declined to. Counting the second is easy — PHPUnit prints the
 * number — but a count cannot tell you whether 101 skips are 101 legitimate platform
 * exclusions or one absent extension taking a hundred tests down with it. Only the
 * reason can, and the reason is exactly what nothing preserves.
 *
 * The JUnit report cannot supply it. PHPUnit's own writer settles the question:
 * JunitXmlLogger::handleIncompleteOrSkipped() does
 *
 *     $skipped = $this->document->createElement('skipped');
 *     $this->currentTestCase->appendChild($skipped);
 *
 * — an empty element, unconditionally, with no message, no attribute, no branch and no
 * setting to change it. Failures and errors go through handleFault(), which does carry
 * their message; skips do not. So the report knows how many and never why, and no
 * option exists to make it say more.
 *
 * PHPUnit's event system does have the reason, so this takes it from there.
 *
 * WHY IT IS OPT-IN
 *
 * The ledger is written only when PULSAR_SKIP_LEDGER_DIR names a directory, and each
 * process writes its own file inside it. Both parts are deliberate:
 *
 *  - Under a parallel runner, six workers write at once. Per-process files remove the
 *    interleaving question entirely rather than relying on append atomicity.
 *  - A ledger left over from a previous run would be merged into the next run's verdict
 *    and quietly answer for tests that never executed. Requiring the caller to name (and
 *    therefore to clear) the directory makes stale data impossible instead of unlikely —
 *    which matters here more than convenience, because this ledger exists to be trusted.
 */
final class SkipLedgerExtension implements Extension
{
    /**
     * Names the directory the ledger is written to. Unset means no ledger at all.
     */
    public const string DIRECTORY_ENV = 'PULSAR_SKIP_LEDGER_DIR';

    #[Override]
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $directory = getenv(self::DIRECTORY_ENV);

        if ($directory === false || trim($directory) === '' || !is_dir($directory)) {
            return;
        }

        $path = rtrim($directory, '/\\')
            . '/skips-' . (getmypid() ?: 0) . '-' . bin2hex(random_bytes(4)) . '.jsonl';

        $facade->registerSubscriber(new class ($path) implements SkippedSubscriber {
            public function __construct(private readonly string $path) {}

            #[Override]
            public function notify(Skipped $event): void
            {
                $line = json_encode(
                    [
                        'test' => $event->test()->id(),
                        // Newlines would break the one-record-per-line contract, and a
                        // skip reason is occasionally multi-line.
                        'reason' => trim(str_replace(["\r\n", "\r", "\n"], ' ', $event->message())),
                    ],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                );

                if ($line === false) {
                    return;
                }

                file_put_contents($this->path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
            }
        });
    }
}
