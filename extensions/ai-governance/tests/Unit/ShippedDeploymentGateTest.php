<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Audit\NullAuditLogger;
use Pulsar\Config\TrustedExtensionsConfig;
use Pulsar\Container\Container;
use Pulsar\Core\Boot\ExtensionSandbox;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extensibility\ExtensionBootstrap;
use Pulsar\Extensibility\ExtensionConfigRegistry;
use Pulsar\Extensibility\TrustTier;
use Pulsar\Extension\AiGovernance\AiGovernanceServiceProvider;
use Pulsar\Extension\AiGovernance\Contracts\AiAuditLoggerInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiDataGovernanceInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiImpactAssessmentInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiLifecycleManagerInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiModelRegistryInterface;
use Pulsar\Extension\AiGovernance\Contracts\ExplainabilityInterface;
use Pulsar\Extension\AiGovernance\Contracts\MonitoringHookInterface;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use Pulsar\Extension\AiGovernance\Dto\ModelCard;
use Pulsar\Extension\AiGovernance\Enum\AiModelRiskLevel;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;
use Pulsar\Extension\AiGovernance\Exception\AiGovernanceException;
use Pulsar\Extension\AiGovernance\Tests\Support\AiGovernanceSchema;

use function array_column;
use function array_slice;
use function dirname;
use function sprintf;

use const DIRECTORY_SEPARATOR;

/**
 * The AI Act gates as a shipped deployment gets them: through the extension
 * sandbox, at the tier `config/extensions.php` actually grants.
 *
 * {@see RiskTierDeploymentWiringTest} calls the service provider against a bare
 * container, which is the right test of the WIRING and no test at all of
 * whether an operator can reach it. It cannot be: a bare container has no
 * capability policy, so it never builds the
 * {@see \Pulsar\Extensibility\Internal\ScopedContainerProxy} that a real boot
 * puts between the provider and the container.
 *
 * ## What this file used to assert, and why it was wrong
 *
 * It required the shipped tier to be `core` — the tier that bypasses both
 * proxies — on the ground that "the sandbox denies by default every service id
 * the framework's restriction map does not classify, and an extension's own
 * contracts are never in that map". That is false against this tree, and was
 * when it was written: an id the extension bound through its own scope is
 * rescued by `Internal\ScopeRegistrations`, and a type it ships is rescued by
 * `ScopedContainerProxy::isOwnCode()`, both consulted after the restriction map.
 * The premise was never executed; a `core` grant was taken on its word.
 *
 * Booting at `verified` names the real defect immediately, and it is a HOST
 * service rather than one of this extension's: `ExtensionConfigRegistry`, which
 * every bundled provider reads its own configuration out of, was in no category
 * of the restriction map. `pulsar/booking` and `pulsar/payments` failed the same
 * way inside `boot()`. It is classified now, and the scope hands back a view
 * narrowed to the sections the receiving extension itself ships.
 *
 * So the assertion is inverted: the tier must keep the sandbox ENGAGED, and the
 * gates must run anyway. A future edit that raises the tier fails here, and it
 * fails with instructions for establishing the need the way this one was
 * disproved — by booting at the lower tier and reading the error.
 *
 * Every case goes through {@see ExtensionSandbox::harden()} reading the
 * repository's real `config/extensions.php`, so the tier under test is the
 * shipped one and not a fixture.
 */
#[CoversClass(AiGovernanceServiceProvider::class)]
final class ShippedDeploymentGateTest extends TestCase
{
    private const string EXTENSION = 'pulsar/ai-governance';

    #[Test]
    public function theShippedTrustConfigKeepsTheSandboxEngagedForThisExtension(): void
    {
        $allowList = TrustedExtensionsConfig::fromArray($this->shippedTrustedExtensions());

        self::assertNotSame(
            TrustTier::Core,
            $allowList->effectiveTier(self::EXTENSION, TrustTier::Core),
            sprintf(
                'config/extensions.php grants "%s" the core tier, which hands it the unwrapped container and '
                . 'the unwrapped router — so every other case in this file would exercise no sandbox and pass '
                . 'vacuously. If something genuinely does not work below core, boot it at the lower tier, read '
                . 'the CapabilityDeniedException, and fix what it names; the last core grant here rested on a '
                . 'claim about deny-by-default that nobody had run.',
                self::EXTENSION,
            ),
        );
    }

    #[Test]
    public function everyContractTheProviderPromisesResolvesThroughTheSandbox(): void
    {
        $container = $this->bootThroughTheSandbox();

        foreach (
            [
                AiModelRegistryInterface::class,
                AiImpactAssessmentInterface::class,
                AiAuditLoggerInterface::class,
                AiDataGovernanceInterface::class,
                ExplainabilityInterface::class,
                AiLifecycleManagerInterface::class,
            ] as $id
        ) {
            self::assertTrue($container->has($id), sprintf('%s must be reachable after a sandboxed boot', $id));
            self::assertInstanceOf($id, $container->get($id));
        }
    }

    #[Test]
    public function bothRiskGatesAreWiredAheadOfTheConfigurableOnesAfterASandboxedBoot(): void
    {
        $manager = $this->manager($this->bootThroughTheSandbox());

        $gates = array_column($manager->evaluateGates($this->model(AiModelRiskLevel::Unacceptable)), 'gate');

        // The two regulation gates first and in this order, so a refusal names
        // the Article rather than a house rule an operator happens to have on.
        self::assertSame(['prohibited_practice', 'high_risk_obligations'], array_slice($gates, 0, 2));
    }

    #[Test]
    public function aProhibitedPracticeIsRefusedInADeploymentThatEnabledTheExtension(): void
    {
        $container = $this->bootThroughTheSandbox();
        $this->registerModel($container, AiModelRiskLevel::Unacceptable);

        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageIsOrContains('Article 5');

        $this->manager($container)->deploy('m1');
    }

    #[Test]
    public function aProhibitedPracticeStaysOutOfProductionAfterTheRefusal(): void
    {
        $container = $this->bootThroughTheSandbox();
        $this->registerModel($container, AiModelRiskLevel::Unacceptable);

        try {
            $this->manager($container)->deploy('m1');
            self::fail('A sandboxed boot must not let an Article 5 prohibited practice reach production.');
        } catch (AiGovernanceException) {
            /** @var AiModelRegistryInterface $registry */
            $registry = $container->get(AiModelRegistryInterface::class);

            self::assertSame(AiModelStatus::Staging, $registry->get('m1')?->status);
        }
    }

    #[Test]
    public function aHighRiskSystemWithoutItsObligationsIsRefusedTheSameWay(): void
    {
        $container = $this->bootThroughTheSandbox();
        $this->registerModel($container, AiModelRiskLevel::High);

        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageIsOrContains('high_risk_obligations');

        $this->manager($container)->deploy('m1');
    }

    /**
     * The shipped config file alone does not let a high-risk system deploy, and
     * that is the posture rc.12 chose.
     *
     * Every provider artefact is on record here — assessment, model card, hook —
     * and the boot reads `config/ai-governance.php` unmodified, where `actor_role`
     * ships COMMENTED OUT. So the refusal is about the one thing the file
     * deliberately does not answer for an operator: whether this deployment is
     * the provider of the system or its deployer. The EU AI Act attaches
     * different obligations to each, and a default would have been the framework
     * answering a legal question on the operator's behalf.
     */
    #[Test]
    public function aHighRiskSystemIsRefusedUntilTheDeploymentDeclaresItsRole(): void
    {
        $container = $this->bootThroughTheSandbox();
        $this->registerModel($container, AiModelRiskLevel::High, $this->card());

        /** @var AiImpactAssessmentInterface $assessments */
        $assessments = $container->get(AiImpactAssessmentInterface::class);
        $assessments->assess('m1');

        $manager = $this->manager($container);
        $manager->addMonitoringHook($this->hook());

        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageIsOrContains('declared neither role');

        $manager->deploy('m1');
    }

    /**
     * With the role declared, the sandboxed boot deploys.
     *
     * This is the one case that overrides the extension's own section rather than
     * reading the file unmodified, because the thing being measured is what
     * happens once an operator has answered the question the file leaves to them.
     * The store keys are not restated: the config DTO defaults every one of them
     * to `database`, so this still boots against the real connection.
     */
    #[Test]
    public function aHighRiskSystemCarryingItsObligationsDeploysAfterASandboxedBoot(): void
    {
        $container = $this->bootThroughTheSandbox(['actor_role' => 'provider']);
        $this->registerModel($container, AiModelRiskLevel::High, $this->card());

        /** @var AiImpactAssessmentInterface $assessments */
        $assessments = $container->get(AiImpactAssessmentInterface::class);
        $assessments->assess('m1');

        $manager = $this->manager($container);
        $manager->addMonitoringHook($this->hook());

        self::assertSame(AiModelStatus::Production, $manager->deploy('m1')->status);
    }

    #[Test]
    public function theSandboxDeliversThisExtensionsOwnConfigSectionAndNoOthers(): void
    {
        // The scope narrows the shared ExtensionConfigRegistry rather than
        // handing it over whole, and both halves of that matter here. Narrowing
        // this extension OUT of its own section would leave the provider reading
        // `[]`, filling in defaults, and every case above still passing on
        // settings nobody chose. Not narrowing at all would hand an AI extension
        // the payments credentials in the same object.
        //
        // `require_model_card` ships false and defaults false, so a `model_card`
        // gate exists only if the operator section below actually arrived.
        $container = $this->bootThroughTheSandbox(['require_model_card' => true]);

        $gates = array_column(
            $this->manager($container)->evaluateGates($this->model(AiModelRiskLevel::High)),
            'gate',
        );

        self::assertContains('model_card', $gates, 'the scoped registry must carry the section this extension ships');
    }

    /**
     * Register the extension exactly as a boot does: discovered from its own
     * manifest, sandboxed against the repository's trust config, and given the
     * scoped container the sandbox builds for its tier.
     *
     * @param array<string, mixed>|null $ownSectionOverride Stands in for a host
     *        that ships its own `config/ai-governance.php`; null uses the file
     *        the extension itself ships.
     */
    private function bootThroughTheSandbox(?array $ownSectionOverride = null): Container
    {
        $root = dirname(__DIR__, 4);
        $extensions = $root . DIRECTORY_SEPARATOR . 'extensions' . DIRECTORY_SEPARATOR;

        $container = new Container();
        $container->instance(AuditLoggerInterface::class, new NullAuditLogger());

        // A real connection, carrying the schema the shipped migration creates.
        // The extension's own config file — which this boot reads, unmodified —
        // sets every store key to `database` since rc.12, and the provider refuses
        // to fall back to memory when nothing is bound. Booting without one would
        // measure a deployment nobody runs.
        $container->instance(ConnectionInterface::class, AiGovernanceSchema::connection());

        // What ExtensionConfigPublisher publishes at boot: every bundled
        // extension's config file, under its section name, in ONE registry.
        // `payments` is here so a scope that stopped narrowing would be handing
        // an AI extension a payment provider's credentials.
        $container->instance(ExtensionConfigRegistry::class, new ExtensionConfigRegistry(
            [
                'ai_governance' => $extensions . 'ai-governance' . DIRECTORY_SEPARATOR
                    . 'config' . DIRECTORY_SEPARATOR . 'ai-governance.php',
                'payments' => $extensions . 'payments' . DIRECTORY_SEPARATOR
                    . 'config' . DIRECTORY_SEPARATOR . 'payments.php',
            ],
            $ownSectionOverride === null ? [] : ['ai_governance' => $ownSectionOverride],
        ));

        $bootstrap = ExtensionBootstrap::create();
        $bootstrap->setEnabledProducts([self::EXTENSION]);
        $bootstrap->setEnabledFilter([self::EXTENSION]);
        $bootstrap->loadFromPaths([$extensions . 'ai-governance']);

        ExtensionSandbox::harden($bootstrap, $root . DIRECTORY_SEPARATOR . 'config');

        $bootstrap->register($container);

        return $container;
    }

    private function manager(Container $container): AiLifecycleManagerInterface
    {
        /** @var AiLifecycleManagerInterface $manager */
        $manager = $container->get(AiLifecycleManagerInterface::class);

        return $manager;
    }

    private function registerModel(
        Container $container,
        AiModelRiskLevel $riskLevel,
        ?ModelCard $card = null,
    ): void {
        /** @var AiModelRegistryInterface $registry */
        $registry = $container->get(AiModelRegistryInterface::class);

        $registry->register($this->model($riskLevel, $card));
    }

    private function model(AiModelRiskLevel $riskLevel, ?ModelCard $card = null): AiModel
    {
        return new AiModel(
            id: 'm1',
            name: 'Applicant scorer',
            version: '1.0.0',
            provider: 'acme',
            type: 'classification',
            riskLevel: $riskLevel,
            status: AiModelStatus::Staging,
            registeredAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            card: $card,
        );
    }

    private function card(): ModelCard
    {
        return new ModelCard(
            description: 'Credit scoring classifier.',
            intendedUse: 'Consumer creditworthiness assessment.',
        );
    }

    private function hook(): MonitoringHookInterface
    {
        $hook = $this->createStub(MonitoringHookInterface::class);
        $hook->method('name')->willReturn('drift-check');

        return $hook;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function shippedTrustedExtensions(): array
    {
        $file = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'extensions.php';

        /** @var mixed $data */
        $data = require $file;

        self::assertIsArray($data);
        self::assertArrayHasKey('trusted_extensions', $data);
        self::assertIsArray($data['trusted_extensions']);

        return $data['trusted_extensions'];
    }
}
