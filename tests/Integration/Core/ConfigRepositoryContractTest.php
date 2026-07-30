<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\OpenApi\OpenApiConfig;
use Pulsar\Cloud\CloudConfig;
use Pulsar\Compliance\ComplianceConfig;
use Pulsar\Config\ApiConfig;
use Pulsar\Config\AppConfig;
use Pulsar\Config\BusinessProfileConfig;
use Pulsar\Config\CacheConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Config\DeployConfig;
use Pulsar\Config\DomainConfig;
use Pulsar\Config\EventConfig;
use Pulsar\Config\FeatureFlagConfig;
use Pulsar\Config\I18nConfig;
use Pulsar\Config\IntegrityConfig;
use Pulsar\Config\MailConfig;
use Pulsar\Config\NotificationConfig;
use Pulsar\Config\ObservabilityConfig;
use Pulsar\Config\QueueConfig;
use Pulsar\Config\ResilienceConfig;
use Pulsar\Config\RoutingConfig;
use Pulsar\Config\RuntimeConfig;
use Pulsar\Config\SchedulerConfig;
use Pulsar\Config\SecurityConfig;
use Pulsar\Config\StorageConfig;
use Pulsar\Config\SupervisorConfig;
use Pulsar\Config\TenancyConfig;
use Pulsar\Core\Wiring\ConfigLoaderRegistrar;
use Pulsar\Core\Wiring\WiringList;
use Pulsar\DataProtection\DataProtectionConfig;
use Pulsar\Documentation\DocumentationConfig;
use Pulsar\Edge\EdgeConfig;
use Pulsar\Introspection\IntrospectionConfig;
use Pulsar\Observability\Profiler\ProfilerConfig;
use Pulsar\Security\AntiSpam\AntiSpamConfigSet;
use Pulsar\View\ViewConfig;

use function array_diff;
use function array_intersect;
use function array_keys;
use function array_map;
use function array_values;
use function basename;
use function dirname;
use function glob;
use function implode;
use function in_array;
use function sort;

use const DIRECTORY_SEPARATOR;

/**
 * M0 wiring-contract gate — config-loading contract (F1).
 *
 * The single source of truth for "which config is loaded" must be the
 * {@see \Pulsar\Config\ConfigRepository}: a real boot builds every shipped
 * config's DTO into it, and consumers read it back typed. A config file that
 * ships, is documented, but whose DTO no production path ever builds is INERT —
 * the operator writes settings that silently do nothing. Half the wiring-audit's
 * inert-config criticals are exactly this.
 *
 * This gate asserts, against the framework's OWN shipped `config/`:
 *  1. every shipped config is explicitly classified — either it is consumed via
 *     the repository, or it is a documented known-gap; an unclassified config
 *     fails, so a newly-shipped config cannot silently be inert;
 *  2. every repository-consumed config builds its DTO in a real `load()`, and no
 *     UNEXPECTED DTO appears — so when a known-gap config is repatriated to the
 *     repository, its DTO surfaces here and forces its promotion out of the
 *     ledger (a repatriated config cannot silently stay listed as a gap).
 *
 * Scope, stated honestly:
 *  - This is the PRODUCER half of the contract: it proves each consumed config's
 *    DTO is BUILT into the repository by a real boot. It does NOT prove a
 *    consumer reads it back via `repository()->get()`; a DTO that is built but
 *    never read would still pass here. Consumer-side wiring (routes dispatch,
 *    middleware piped, the DTO actually acted upon) is the separate route/
 *    middleware contract, M0-F2/F4.
 *  - The known-gaps ledger DOCUMENTS the remaining debt; it does not by itself
 *    force an INERT entry to be remediated. "Ledger empty before a GA tag" is
 *    release policy enforced by review, tracked in
 *    docs/audit/wiring-audit-2026-07/REMEDIATION-TASKS.md (F1) — not by a
 *    self-failing assertion here (that would just red the suite for known,
 *    scheduled work).
 */
#[CoversClass(ConfigManager::class)]
final class ConfigRepositoryContractTest extends TestCase
{
    /**
     * Config basename => the DTO class a real boot must build into the
     * repository. This is the converged set: read back via
     * `repository()->get(Dto::class)`, single source of truth.
     *
     * @var array<string, class-string>
     */
    private const array CONSUMED_VIA_REPOSITORY = [
        'app' => AppConfig::class,
        'observability' => ObservabilityConfig::class,
        'security' => SecurityConfig::class,
        'i18n' => I18nConfig::class,
        'event' => EventConfig::class,
        'database' => DatabaseConfig::class,
        'tenancy' => TenancyConfig::class,
        'features' => FeatureFlagConfig::class,
        'scheduler' => SchedulerConfig::class,
        'resilience' => ResilienceConfig::class,
        'queue' => QueueConfig::class,
        'routing' => RoutingConfig::class,
        'storage' => StorageConfig::class,
        'supervisor' => SupervisorConfig::class,
        'integrity' => IntegrityConfig::class,
        'deploy' => DeployConfig::class,
        'runtime' => RuntimeConfig::class,
        'cache' => CacheConfig::class,
        'mail' => MailConfig::class,
        'notification' => NotificationConfig::class,
        'api' => ApiConfig::class,
        'view' => ViewConfig::class,
        'business' => BusinessProfileConfig::class,
        // Repatriated from the ad-hoc direct-read path into the repository via a
        // ProvidesConfigLoaders loader (M0-F1 convergence).
        'edge' => EdgeConfig::class,
        'documentation' => DocumentationConfig::class,
        'profiler' => ProfilerConfig::class,
        'data_protection' => DataProtectionConfig::class,
        'domains' => DomainConfig::class,
        'introspection' => IntrospectionConfig::class,
        // One file, several typed sub-sections → one AntiSpamConfigSet DTO in the
        // repository (see AntiSpamConfigSet); AntiSpamWiring distributes the parts.
        'anti-spam' => AntiSpamConfigSet::class,
        'openapi' => OpenApiConfig::class,
        // Resolved into the strictest ComplianceProfile and enforced onto
        // security-relevant config by ComplianceWiring (Tier-1 enforcement epic).
        'compliance' => ComplianceConfig::class,
        // Optional; no default config/cloud.php ships, so its DTO is only in the
        // repository when a project adds the file. Listed so it is never flagged
        // as an unexpected DTO.
        'cloud' => CloudConfig::class,
    ];

    /**
     * Known gaps: shipped configs NOT (yet) consumed via the repository, each
     * with the reason. This is the explicit M0-F1 worklist; it must shrink to
     * only the deliberate separate-path entries. Reconfirmed vs HEAD 2026-07-23.
     *
     * @var array<string, string>
     */
    private const array KNOWN_GAPS = [
        // Extension-consumed — the owning extension reads the file directly.
        'admin' => 'extension-consumed: pulsar/admin reads it directly',
        'opentelemetry' => 'extension-consumed: pulsar/opentelemetry reads it directly',
        // CLI-command config — read directly by bin/pulsar (require config/X.php →
        // XConfig::fromArray → command factory), not a boot-repository DTO. These
        // are command-scoped and only needed by their commands.
        'dev' => 'CLI-command config: bin/pulsar reads it into DevConfig for the dev:* commands',
        'repl' => 'CLI-command config: bin/pulsar reads it into ReplConfig for the repl/shell commands',
        'supply-chain' => 'CLI-command config: bin/pulsar reads it into SupplyChainConfig for the supply-chain:* commands',
        // INERT — shipped and documented, but NO production consumer. Wire a
        // consumer (build DTO + act on it) or build out the half-built feature.
        'broadcasting' => 'INERT: WebSocket/broadcast stack exists but WebSocketConfig is built nowhere',
        'live' => 'INERT: Live reactive-component module not wired/routed; LiveConfig unused',
        'marketplace' => 'INERT: extension-marketplace half-built (registry_url config + discovery API, no HTTP client/CLI — build epic)',
        // Deliberate separate boot paths (not ConfigManager::load()).
        'studio' => 'separate path: loaded by Kernel::studioPreboot',
        'extensions' => 'not a config DTO: extension trust map, read by ExtensionDiscovery',
    ];

    #[Test]
    public function everyShippedConfigIsExplicitlyClassified(): void
    {
        $shipped = $this->shippedConfigBasenames();
        $classified = [...array_keys(self::CONSUMED_VIA_REPOSITORY), ...array_keys(self::KNOWN_GAPS)];

        // 1. No config is in BOTH lists.
        $both = array_values(array_intersect(
            array_keys(self::CONSUMED_VIA_REPOSITORY),
            array_keys(self::KNOWN_GAPS),
        ));
        sort($both);
        self::assertSame([], $both, 'Config classified as both consumed and a known-gap: ' . implode(', ', $both));

        // 2. Every shipped config is classified (consumed or a documented gap).
        $unclassified = array_diff($shipped, $classified);
        sort($unclassified);
        self::assertSame(
            [],
            $unclassified,
            'Shipped config(s) with no classification — add to CONSUMED_VIA_REPOSITORY or KNOWN_GAPS so a new '
            . 'config cannot silently ship inert: ' . implode(', ', $unclassified),
        );

        // 3. No stale entry: every classified basename (except the optional,
        //    not-shipped-by-default 'cloud') actually ships.
        $stale = array_diff($classified, [...$shipped, 'cloud']);
        sort($stale);
        self::assertSame([], $stale, 'Classified config no longer shipped — remove the stale entry: ' . implode(', ', $stale));
    }

    #[Test]
    public function consumedConfigsBuildTheirDtoInARealBoot(): void
    {
        $repository = $this->bootRealConfig()->repository();
        $loaded = array_map(static fn(object $o): string => $o::class, $repository->all());

        // Every shipped repository-consumed config builds its DTO.
        foreach (self::CONSUMED_VIA_REPOSITORY as $name => $dtoClass) {
            if (!$this->isShipped($name)) {
                continue; // e.g. cloud: no default file
            }
            self::assertContains(
                $dtoClass,
                $loaded,
                "config/{$name}.php ships but its DTO {$dtoClass} was not built into the repository by a real boot — inert.",
            );
        }

        // No UNEXPECTED DTO: everything in the repository is an expected consumed
        // DTO. When a known-gap config is repatriated, its DTO appears here and
        // this fails until it is promoted into CONSUMED_VIA_REPOSITORY — the
        // ledger cannot silently retain a config that is already converged.
        $unexpected = array_diff($loaded, array_values(self::CONSUMED_VIA_REPOSITORY));
        sort($unexpected);
        self::assertSame(
            [],
            $unexpected,
            'The repository holds DTO(s) not in CONSUMED_VIA_REPOSITORY. A known-gap config was likely repatriated — '
            . 'move it from KNOWN_GAPS to CONSUMED_VIA_REPOSITORY: ' . implode(', ', $unexpected),
        );
    }

    #[Test]
    public function theShippedConfigProducesNoUnknownKeyWarnings(): void
    {
        // The framework's OWN default config must be clean: a real boot of config/
        // must not flag a single key as unknown. This catches a DTO whose
        // KNOWN_KEYS omits a legitimate key its section (or a sibling section
        // reading the same file) actually ships — which surfaces false-positive
        // "unrecognized key" warnings on every default install, and in strict mode
        // aborts boot outright. It also guards against drift as sections gain keys.
        $warnings = $this->bootRealConfig()->unknownConfigKeyWarnings();
        sort($warnings);

        self::assertSame(
            [],
            $warnings,
            'The shipped config/ must produce zero unknown-key warnings — a DTO is flagging one of its own '
            . 'legitimate keys. Got: ' . implode(' | ', $warnings),
        );
    }

    /**
     * @return list<string>
     */
    private function shippedConfigBasenames(): array
    {
        $configDir = $this->repoRoot() . DIRECTORY_SEPARATOR . 'config';
        $files = glob($configDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        $names = array_map(static fn(string $f): string => basename($f, '.php'), $files);
        sort($names);

        return $names;
    }

    private function isShipped(string $name): bool
    {
        return in_array($name, $this->shippedConfigBasenames(), true);
    }

    private function bootRealConfig(): ConfigManager
    {
        $configManager = new ConfigManager(configPath: $this->repoRoot() . DIRECTORY_SEPARATOR . 'config');
        // Register wiring-owned config loaders exactly as Kernel does at boot, so
        // loader-built DTOs (the converged path) land in the repository. Shared
        // registrar => the gate can never drift from the real boot.
        ConfigLoaderRegistrar::register($configManager, WiringList::default());
        $configManager->load();

        return $configManager;
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
