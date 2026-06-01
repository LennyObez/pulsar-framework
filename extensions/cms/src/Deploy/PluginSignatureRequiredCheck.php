<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Deploy;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use Pulsar\Deploy\CheckResult;
use Pulsar\Deploy\DeployCheckInterface;
use Pulsar\Extension\Cms\Config\CmsSecurityConfig;

use function class_exists;

/**
 * Validates that CMS plugin signature enforcement is enabled in staging /
 * production environments.
 *
 * SEC-EXT-02 (external audit): `CmsSecurityConfig::requireSignedPlugins`
 * defaults to false so dev / test environments can iterate on unsigned
 * plugin packages. Production deployments MUST flip this to true so the
 * `CmsPluginManager` refuses to load plugins whose Ed25519 signature was
 * not verified against the trusted_public_keys allowlist. Without this
 * gate, a tampered plugin archive — or a plugin installed without a
 * signature — boots unchanged.
 *
 * Permissive when the CMS extension is not installed.
 */
#[Internal]
final readonly class PluginSignatureRequiredCheck implements DeployCheckInterface
{
    private const string CHECK_NAME = 'cms-plugin-signature-required';

    public function __construct(
        private ContainerInterface $container,
    ) {}

    #[Override]
    public function getName(): string
    {
        return self::CHECK_NAME;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Validates CMS plugin signatures are required in staging/production';
    }

    #[Override]
    public function check(string $environment): CheckResult
    {
        if ($environment === 'local') {
            return CheckResult::pass(
                self::CHECK_NAME,
                'Plugin signature requirement is not enforced in local environment',
            );
        }

        if (!class_exists(CmsSecurityConfig::class)) {
            return CheckResult::pass(
                self::CHECK_NAME,
                'CMS extension is not installed; check skipped',
            );
        }

        if (!$this->container->has(CmsSecurityConfig::class)) {
            return CheckResult::pass(
                self::CHECK_NAME,
                'CmsSecurityConfig is not bound; CMS features are not active',
            );
        }

        /** @var CmsSecurityConfig $config */
        $config = $this->container->get(CmsSecurityConfig::class);

        if ($config->requireSignedPlugins) {
            return CheckResult::pass(
                self::CHECK_NAME,
                'cms.security.require_signed_plugins=true — unsigned plugins are refused at load time',
            );
        }

        return CheckResult::error(
            self::CHECK_NAME,
            "cms.security.require_signed_plugins=false in $environment",
            [
                'Set `require_signed_plugins: true` in config/cms.php (security section).',
                'Without this flag, CmsPluginManager loads plugins regardless of their signature',
                'verification status — a tampered archive or a plugin installed without a valid',
                'Ed25519 signature against the trusted_public_keys allowlist still boots.',
                'PCI Req 6.3.1 (security policy enforcement), HIPAA §164.312(c)(1) (integrity),',
                'and ISO 27001 A.8.12 (intrusion detection) all require this control.',
            ],
        );
    }
}
