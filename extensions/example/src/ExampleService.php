<?php

declare(strict_types=1);

namespace Pulsar\Extension\Example;

use Pulsar\Core\Version;

/**
 * Example service demonstrating DI integration.
 */
final class ExampleService
{
    /**
     * Get a greeting message.
     */
    public function getGreeting(string $name = 'World'): string
    {
        return sprintf('Hello, %s!', $name);
    }

    /**
     * Get extension information.
     *
     * @return array{extension: string, framework_version: string, php_version: string}
     */
    public function getInfo(): array
    {
        return [
            'extension' => 'pulsar/example',
            'framework_version' => Version::full(),
            'php_version' => PHP_VERSION,
        ];
    }
}
