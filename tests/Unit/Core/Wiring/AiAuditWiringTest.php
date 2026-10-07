<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\AiClientInterface;
use Pulsar\AI\Audit\AuditingAiClient;
use Pulsar\AI\Audit\EgressDecisionSinkInterface;
use Pulsar\AI\Audit\EgressDecisionSourceInterface;
use Pulsar\AI\Audit\Internal\PendingEgressDecision;
use Pulsar\AI\ChatMessage;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\Exception\ConfigException;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\AiAuditWiring;
use Pulsar\Core\Wiring\SecurityPostureWiring;
use Pulsar\Core\Wiring\WiringList;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Crypto\KeyProviderInterface;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Tests\Unit\AI\Audit\Support\FakeAiClient;
use Pulsar\Tests\Unit\AI\Audit\Support\RecordingAuditSink;

use function array_map;
use function array_search;
use function bin2hex;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * The AI audit wiring fails closed, and composes the auditor OUTERMOST.
 *
 * FAIL CLOSED, not degrade, and the departure from the house pattern is
 * deliberate. Every other wiring that touches the trail asks
 * `has(AuditLoggerInterface::class)` and falls back to a null logger, because
 * there the audit entry is a side effect of work that still has value
 * unrecorded. For an inference the record IS the regulatory artefact, so a bound
 * AI client with no audit chain aborts boot rather than quietly performing
 * unauditable model calls behind a component named "audit".
 *
 * A deployment that does not use the AI layer is not made to hold a key for one:
 * with no `AiClientInterface` bound, nothing is demanded and nothing is bound.
 */
#[CoversClass(AiAuditWiring::class)]
final class AiAuditWiringTest extends TestCase
{
    private string $configPath = '';

    #[Override]
    protected function tearDown(): void
    {
        if ($this->configPath !== '' && is_dir($this->configPath)) {
            self::removeTree($this->configPath);
        }
    }

    #[Test]
    public function aBoundAiClientWithNoAuditChainAbortsBoot(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/would leave no record/');

        $this->wire(withAiClient: true, withAuditChain: false);
    }

    #[Test]
    public function withNoAiClientNothingIsDemandedAndNothingIsBound(): void
    {
        $container = $this->wire(withAiClient: false, withAuditChain: false);

        self::assertFalse($container->has(AiClientInterface::class));
        self::assertFalse($container->has(EgressDecisionSourceInterface::class));
        self::assertFalse($container->has(EgressDecisionSinkInterface::class));
    }

    #[Test]
    public function theBoundClientIsReplacedByAnAuditingOneThatStillReachesTheProvider(): void
    {
        $sink = new RecordingAuditSink();
        $container = $this->wire(withAiClient: true, withAuditChain: true, auditSink: $sink);

        $client = $container->get(AiClientInterface::class);

        self::assertInstanceOf(AuditingAiClient::class, $client);
        self::assertSame('fake', $client->providerName());

        $client->chat([ChatMessage::user('hello')]);

        self::assertCount(1, $sink->entries);
        self::assertSame(AuditingAiClient::ACTION, $sink->entries[0]->action);
    }

    #[Test]
    public function noEgressChannelIsFabricatedWhereNoControlReportsIntoOne(): void
    {
        $container = $this->wire(withAiClient: true, withAuditChain: true);

        // A channel bound next to no control would make every record claim the
        // payload was examined and found clean. Absence is left as absence.
        self::assertFalse($container->has(EgressDecisionSinkInterface::class));
        self::assertFalse($container->has(EgressDecisionSourceInterface::class));
    }

    #[Test]
    public function anEgressSeamBoundBeforeThisWiringIsNotReplacedUnderTheControlUsingIt(): void
    {
        $existing = new PendingEgressDecision();

        $container = $this->wire(
            withAiClient: true,
            withAuditChain: true,
            preboundEgress: $existing,
        );

        self::assertSame($existing, $container->get(EgressDecisionSourceInterface::class));
    }

    #[Test]
    public function itRunsAfterSecurityWiringAndBeforeTheSecurityPostureCheck(): void
    {
        $order = array_map(static fn(object $wiring): string => $wiring::class, WiringList::default());

        $ai = array_search(AiAuditWiring::class, $order, true);
        $posture = array_search(SecurityPostureWiring::class, $order, true);

        self::assertIsInt($ai, 'AiAuditWiring must be in the canonical boot list, or no boot audits inferences');
        self::assertIsInt($posture);
        self::assertLessThan(
            $posture,
            $ai,
            'the posture preflight must judge a container that already holds the audited client',
        );
    }

    private function wire(
        bool $withAiClient,
        bool $withAuditChain,
        ?RecordingAuditSink $auditSink = null,
        ?PendingEgressDecision $preboundEgress = null,
    ): Container {
        $container = new Container();

        if ($withAiClient) {
            $container->instance(AiClientInterface::class, new FakeAiClient());
        }

        if ($withAuditChain) {
            $container->instance(KeyProviderInterface::class, MasterKey::fromHex(bin2hex(random_bytes(32))));
            $container->instance(
                AuditLoggerInterface::class,
                new AuditLogger($auditSink ?? new RecordingAuditSink(), random_bytes(32)),
            );
        }

        if ($preboundEgress !== null) {
            $container->instance(EgressDecisionSinkInterface::class, $preboundEgress);
            $container->instance(EgressDecisionSourceInterface::class, $preboundEgress);
        }

        new AiAuditWiring()->wire(
            $container,
            $this->configManager(),
            new MiddlewarePipeline($container),
            new MiddlewareRegistry(),
            new Router(),
        );

        return $container;
    }

    private function configManager(): ConfigManager
    {
        $this->configPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_ai_audit_' . bin2hex(random_bytes(4));
        mkdir($this->configPath, 0o755, true);

        foreach (['app.php', 'observability.php', 'security.php'] as $file) {
            file_put_contents($this->configPath . DIRECTORY_SEPARATOR . $file, '<?php return [];');
        }

        $manager = new ConfigManager($this->configPath);
        $manager->load();

        return $manager;
    }

    private static function removeTree(string $path): void
    {
        $entries = scandir($path);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($child)) {
                self::removeTree($child);

                continue;
            }

            if (is_file($child)) {
                unlink($child);
            }
        }

        rmdir($path);
    }
}
