<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Boot;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Core\Boot\ExtensionSandbox;
use Pulsar\Extensibility\CapabilityPolicy;
use Pulsar\Extensibility\ExtensionBootstrap;
use Pulsar\Extensibility\TrustTier;

use function file_exists;
use function file_put_contents;
use function getmypid;
use function is_dir;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(ExtensionSandbox::class)]
final class ExtensionSandboxTest extends TestCase
{
    private string $configDir;

    protected function setUp(): void
    {
        $this->configDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_sbx_' . getmypid();

        if (!is_dir($this->configDir)) {
            mkdir($this->configDir, 0o777, true);
        }
    }

    protected function tearDown(): void
    {
        $file = $this->configDir . DIRECTORY_SEPARATOR . 'extensions.php';

        if (is_dir($this->configDir)) {
            if (file_exists($file)) {
                unlink($file);
            }
            rmdir($this->configDir);
        }
    }

    #[Test]
    public function hardenAttachesTheDefaultPolicyWhenNoneIsConfigured(): void
    {
        $bootstrap = ExtensionBootstrap::create();
        self::assertNull($bootstrap->capabilityPolicy);

        ExtensionSandbox::harden($bootstrap, null);

        self::assertNotNull($bootstrap->capabilityPolicy, 'a policy must be attached so the proxies engage');
        self::assertNotNull($bootstrap->trustedExtensionsConfig, 'a host trust config must be attached');
    }

    #[Test]
    public function hardenIsANoopWhenAPolicyIsAlreadyConfigured(): void
    {
        $bootstrap = ExtensionBootstrap::create();

        // An embedder (or test) that supplied its own custom policy keeps it.
        $custom = new CapabilityPolicy([]);
        $bootstrap->capabilityPolicy = $custom;

        ExtensionSandbox::harden($bootstrap, $this->configDir);

        self::assertSame($custom, $bootstrap->capabilityPolicy);
        self::assertNull($bootstrap->trustedExtensionsConfig, 'harden must not overwrite an explicit configuration');
    }

    #[Test]
    public function withoutAConfigPathUnknownExtensionsAreCappedAtCommunity(): void
    {
        // Fail-closed: no config to read, but the sandbox still engages and an
        // extension requesting Core is capped at Community.
        $bootstrap = ExtensionBootstrap::create();

        ExtensionSandbox::harden($bootstrap, null);

        self::assertNotNull($bootstrap->trustedExtensionsConfig);
        self::assertSame(
            TrustTier::Community,
            $bootstrap->trustedExtensionsConfig->effectiveTier('acme/unknown', TrustTier::Core),
            'an unlisted extension cannot self-elevate to Core',
        );
    }

    #[Test]
    public function hardenReadsTheTrustedListFromTheConfigDirectory(): void
    {
        file_put_contents(
            $this->configDir . DIRECTORY_SEPARATOR . 'extensions.php',
            "<?php return ['trusted_extensions' => ['vendor/trusted' => ['tier' => 'verified']]];\n",
        );

        $bootstrap = ExtensionBootstrap::create();
        ExtensionSandbox::harden($bootstrap, $this->configDir);

        self::assertNotNull($bootstrap->trustedExtensionsConfig);

        // Listed at verified: a Core request is capped to Verified.
        self::assertSame(
            TrustTier::Verified,
            $bootstrap->trustedExtensionsConfig->effectiveTier('vendor/trusted', TrustTier::Core),
        );

        // Not listed: capped at Community regardless of request.
        self::assertSame(
            TrustTier::Community,
            $bootstrap->trustedExtensionsConfig->effectiveTier('vendor/other', TrustTier::Core),
        );
    }

    #[Test]
    public function malformedConfigFailsClosedRatherThanDisablingTheSandbox(): void
    {
        // A config file that does not return the expected shape must not leave
        // the policy null (sandbox disabled); it engages with an empty trust list.
        file_put_contents(
            $this->configDir . DIRECTORY_SEPARATOR . 'extensions.php',
            "<?php return 'not-an-array';\n",
        );

        $bootstrap = ExtensionBootstrap::create();
        ExtensionSandbox::harden($bootstrap, $this->configDir);

        self::assertNotNull($bootstrap->capabilityPolicy);
        self::assertNotNull($bootstrap->trustedExtensionsConfig);
        self::assertSame(
            TrustTier::Community,
            $bootstrap->trustedExtensionsConfig->effectiveTier('pulsar/orm', TrustTier::Core),
        );
    }
}
