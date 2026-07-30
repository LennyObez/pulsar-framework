<?php

declare(strict_types=1);

namespace Pulsar\SupplyChain\Vex;

use NoDiscard;
use Pulsar\Api\Api;

use function is_string;
use function preg_match;
use function rtrim;

use const DIRECTORY_SEPARATOR;

/**
 * Configuration for VEX (Vulnerability Exploitability eXchange) generation.
 *
 * The source directory is scanned for imports to decide which vulnerable
 * packages are actually reachable; the output path is where the OpenVEX
 * document is written. Both are read from the `vex` section of
 * config/supply-chain.php and default to the framework conventions.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class VexConfig
{
    /**
     * @param string $sourceDir Path (relative to the project root) to the source
     *        directory scanned for reachability analysis
     * @param string $outputPath Path (relative to the project root, or absolute)
     *        where the generated VEX document is written
     */
    public function __construct(
        public string $sourceDir = 'src',
        public string $outputPath = 'vex.json',
    ) {}

    /**
     * @param array<string, mixed> $data The `vex` section of config/supply-chain.php
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var mixed $sourceDir */
        $sourceDir = $data['source_dir'] ?? null;
        /** @var mixed $outputPath */
        $outputPath = $data['output_path'] ?? null;

        return new self(
            sourceDir: is_string($sourceDir) && $sourceDir !== '' ? $sourceDir : 'src',
            outputPath: is_string($outputPath) && $outputPath !== '' ? $outputPath : 'vex.json',
        );
    }

    /**
     * Absolute output path: an absolute configured value is used verbatim,
     * otherwise it is resolved against the project root.
     */
    #[NoDiscard]
    public function resolvedOutputPath(string $projectRoot): string
    {
        return self::isAbsolute($this->outputPath)
            ? $this->outputPath
            : rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . $this->outputPath;
    }

    private static function isAbsolute(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        return preg_match('#^[A-Za-z]:[/\\\\]#', $path) === 1;
    }
}
