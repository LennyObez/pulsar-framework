<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;

/**
 * Typed configuration DTO for `config/app.php`.
 *
 * Environment variables `APP_NAME`, `APP_ENV`, `APP_DEBUG` override file values.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AppConfig implements ReportsUnknownKeys
{
    /**
     * Keys recognised in config/app.php. `config` and `extensions` are read by
     * ConfigManager and the entry point rather than by this DTO, but they are
     * legitimate app-config keys and so belong here (see ADR-0036).
     */
    private const array KNOWN_KEYS = ['name', 'env', 'debug', 'timezone', 'locale', 'signature', 'config', 'extensions'];

    public function __construct(
        public string $name,
        public EnvironmentMode $mode,
        public bool $debug,
        public string $timezone,
        public string $locale,
        public AppSignature $signature = new AppSignature(),
        /** @var list<string> */
        public array $unknownKeys = [],
    ) {}

    /**
     * @return list<string>
     */
    public function unknownConfigKeys(): array
    {
        return $this->unknownKeys;
    }

    /**
     * Build an AppConfig from a raw config array and environment.
     *
     * @param array{
     *     name?: string,
     *     env?: string,
     *     debug?: bool|int|string,
     *     timezone?: string,
     *     locale?: string,
     *     signature?: array{generator?: bool|int|string, author?: bool|int|string},
     * } $data Raw array from config/app.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $modeString = $environment->get('APP_ENV') ?? $data['env'] ?? 'local';
        $mode = EnvironmentMode::tryFrom($modeString);

        if ($mode === null) {
            // Fail secure: an explicitly-set but unrecognized APP_ENV (a typo, or
            // an unexpected value) must NOT silently become Local and disclose
            // stack traces / source. Treat anything unrecognized as Production.
            $mode = EnvironmentMode::Production;
        }

        $name = $environment->get('APP_NAME') ?? $data['name'] ?? 'Pulsar';

        $debugEnv = $environment->get('APP_DEBUG');

        if ($debugEnv !== null) {
            $debug = self::parseBool($debugEnv);
        } elseif (isset($data['debug'])) {
            $debug = (bool) $data['debug'];
        } else {
            $debug = $mode->isDebugByDefault();
        }

        /** @var mixed $rawSignature */
        $rawSignature = $data['signature'] ?? [];
        /** @var array{generator?: bool|int|string, author?: bool|int|string} $signatureData */
        $signatureData = is_array($rawSignature) ? $rawSignature : [];

        return new self(
            name: $name,
            mode: $mode,
            debug: $debug,
            timezone: $data['timezone'] ?? 'UTC',
            locale: $data['locale'] ?? 'en',
            signature: AppSignature::fromArray($signatureData),
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }

    private static function parseBool(string $value): bool
    {
        return match (strtolower($value)) {
            '1', 'true', 'yes', 'on' => true,
            default => false,
        };
    }
}
