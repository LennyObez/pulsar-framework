<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Config\ComplianceLoggingConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\Exception\ConfigException;
use Pulsar\Config\ObservabilityConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Observability\Log\Compliance\ComplianceLogFormatter;
use Pulsar\Observability\Log\Compliance\ComplianceLogSink;
use Pulsar\Observability\Log\Compliance\GdprLogFormatter;
use Pulsar\Observability\Log\Compliance\HipaaLogFormatter;
use Pulsar\Observability\Log\Compliance\PciDssLogFormatter;
use Pulsar\Observability\Log\Compliance\SoxLogFormatter;
use Pulsar\Observability\Log\Sink\DeferredSinkInterface;
use Pulsar\Observability\Log\Sink\FileSink;
use Pulsar\Routing\Router;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Security\Crypto\MasterKey;

use function implode;
use function sprintf;

/**
 * Activates the compliance log sink (config: observability `logging.compliance`).
 *
 * Routes every log entry through the regulation-specific formatters (GDPR /
 * HIPAA pseudonymization, PCI-DSS / SOX masking) for the configured frameworks
 * and writes to a dedicated durable file, encrypted at rest when the security
 * encryptor is available. Runs after SecurityWiring because pseudonymization
 * derives its HMAC key from the master key; the sink itself is attached through
 * the DeferredSink that LoggingWiring registered on the logger at boot.
 *
 * Fail-closed: enabling compliance logging without a master key, or naming an
 * unknown framework, is a hard boot error -- an operator who believes log
 * output is masked for a regulation must never silently log unmasked data.
 */
#[Internal]
final readonly class ComplianceLoggingWiring implements ServiceWiringInterface
{
    private const int HMAC_SUB_KEY_ID = 18;

    /** KDF context for compliance-log pseudonymization (exactly 8 bytes). */
    private const string HMAC_CONTEXT = 'cmp_logs';

    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();

        if (!$repository->has(ObservabilityConfig::class)) {
            return;
        }

        /** @var ObservabilityConfig $observabilityConfig */
        $observabilityConfig = $repository->get(ObservabilityConfig::class);
        $config = $observabilityConfig->complianceLogging;

        if (!$config->enabled) {
            return;
        }

        if (!$container->has(MasterKey::class)) {
            throw ConfigException::invalidValue(
                'logging.compliance.enabled',
                'compliance logging requires the security master key (PULSAR_MASTER_KEY) for pseudonymization',
            );
        }

        if (!$container->has(DeferredSinkInterface::class)) {
            throw ConfigException::invalidValue(
                'logging.compliance.enabled',
                'compliance logging requires the logging subsystem (DeferredSink) to be wired',
            );
        }

        /** @var MasterKey $masterKey */
        $masterKey = $container->get(MasterKey::class);
        $hmacKey = $masterKey->deriveSubKey(self::HMAC_SUB_KEY_ID, self::HMAC_CONTEXT);

        $formatters = [];

        foreach ($config->frameworks as $framework) {
            $formatters[] = $this->formatterFor($framework, $hmacKey);
        }

        $encryptor = $container->has(EncryptorInterface::class)
            ? $container->get(EncryptorInterface::class)
            : null;
        /** @var EncryptorInterface|null $encryptor */

        $sink = new ComplianceLogSink(
            new FileSink(resolve_path($config->path)),
            $encryptor,
            ...$formatters,
        );
        $container->instance(ComplianceLogSink::class, $sink);

        /** @var DeferredSinkInterface $deferredSink */
        $deferredSink = $container->get(DeferredSinkInterface::class);
        $deferredSink->addSink($sink);
    }

    private function formatterFor(string $framework, string $hmacKey): ComplianceLogFormatter
    {
        return match ($framework) {
            'gdpr' => new GdprLogFormatter($hmacKey),
            'hipaa' => new HipaaLogFormatter($hmacKey),
            'pci-dss' => new PciDssLogFormatter(),
            'sox' => new SoxLogFormatter(),
            default => throw ConfigException::invalidValue(
                'logging.compliance.frameworks',
                sprintf(
                    'unknown compliance framework "%s" (known: %s)',
                    $framework,
                    implode(', ', ComplianceLoggingConfig::KNOWN_FRAMEWORKS),
                ),
            ),
        };
    }
}
