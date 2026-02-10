<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Manifest;

use Pulsar\Api\Internal;
use Pulsar\Extension\Grpc\Handler\ServiceHandlerInterface;
use Pulsar\Extension\Grpc\Server\ServiceRegistryInterface;
use RuntimeException;

use function array_map;
use function date;
use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function sprintf;

/**
 * Compiles a service manifest from the service registry.
 *
 * Iterates over all registered services and their method descriptors,
 * producing a ServiceManifest DTO. Can also write the manifest as
 * a PHP array file for zero-overhead boot-time loading.
 */
#[Internal(reason: 'Build-time manifest compilation')]
final class ManifestCompiler
{
    /**
     * Compile a service manifest from the registry.
     */
    public function compile(ServiceRegistryInterface $registry): ServiceManifest
    {
        $entries = [];

        foreach ($registry->serviceNames() as $serviceName) {
            $methods = $registry->methodsForService($serviceName);
            $handler = null;

            // Resolve handler class from the first method's full name
            foreach ($methods as $methodName => $descriptor) {
                $handler = $registry->resolveHandler($descriptor->fullName);

                break;
            }

            $handlerClass = $handler instanceof ServiceHandlerInterface
                ? $handler::class
                : '';

            $methodArrays = array_map(
                static fn($descriptor) => $descriptor->toArray(),
                $methods,
            );

            $entries[] = new ManifestEntry(
                serviceName: $serviceName,
                handlerClass: $handlerClass,
                methods: array_values($methodArrays),
            );
        }

        return new ServiceManifest(
            services: $entries,
            version: '1.0',
            compiledAt: date('c'),
        );
    }

    /**
     * Write the manifest as a PHP array file for fast boot-time loading.
     */
    public function writePhpFile(ServiceManifest $manifest, string $outputPath): void
    {
        $dir = dirname($outputPath);

        if (!is_dir($dir) && !mkdir($dir, 0o755, true)) {
            throw new RuntimeException(sprintf('Cannot create directory: %s', $dir));
        }

        $content = $manifest->toPhpArray();
        $written = file_put_contents($outputPath, $content);

        if ($written === false) {
            throw new RuntimeException(sprintf('Failed to write manifest to: %s', $outputPath));
        }
    }
}
