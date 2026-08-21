<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Support;

use function file_get_contents;
use function glob;
use function preg_match;
use function str_contains;
use function str_replace;
use function ucwords;

use const DIRECTORY_SEPARATOR;
use const GLOB_ONLYDIR;

/**
 * The rule behind ArchitectureRulesTest::deptrac_config_covers_all_extensions, pointed at
 * a tree rather than at this one.
 *
 * An extension missing from tools/php/deptrac.yaml is not reported by Deptrac as
 * unanalysed — it is simply not analysed, and `deptrac analyse` is green over it. So the
 * structural boundary gate can pass while an entire extension sits outside it, and the
 * only thing standing between that and a merge is this rule. A rule in that position that
 * has never been watched refusing is the finding this work exists to close, one level up.
 */
final readonly class DeptracCoverage
{
    public function __construct(private string $root) {}

    /**
     * Extensions whose namespace the Deptrac configuration never names.
     *
     * @return list<string>
     */
    public function uncoveredExtensions(string $configPath): array
    {
        $config = (string) file_get_contents($configPath);
        $uncovered = [];

        foreach ($this->extensionDirectories() as $directory) {
            if (preg_match('/extensions[\\\\\/]([^\\\\\/]+)[\\\\\/]src$/', $directory, $matches) !== 1) {
                continue;
            }

            $name = $matches[1];
            $namespace = 'Pulsar\\\\Extension\\\\' . self::namespaceSegmentFor($name);

            if (!str_contains($config, $namespace)) {
                $uncovered[] = $name;
            }
        }

        return $uncovered;
    }

    /**
     * @return list<string>
     */
    public function extensionDirectories(): array
    {
        return glob(
            $this->root . DIRECTORY_SEPARATOR . 'extensions' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'src',
            GLOB_ONLYDIR,
        ) ?: [];
    }

    /**
     * Directory name to namespace segment: social-sso -> SocialSso, oauth2 -> OAuth2.
     */
    public static function namespaceSegmentFor(string $extensionName): string
    {
        $segment = str_replace(' ', '', ucwords(str_replace('-', ' ', $extensionName)));

        return match ($segment) {
            'Oauth2' => 'OAuth2',
            'Opentelemetry' => 'OpenTelemetry',
            'Webauthn' => 'WebAuthn',
            default => $segment,
        };
    }
}
