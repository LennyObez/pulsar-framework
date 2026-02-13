<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Make\Template;

use function implode;

/**
 * Template generator for make:port scaffolding.
 */
final readonly class PortTemplates
{
    /**
     * @param list<string> $methods
     */
    public function portInterface(string $name, string $namespace, array $methods): string
    {
        $interfaceName = $name . 'Interface';
        $methodStubs = '';

        if ($methods !== []) {
            $stubs = [];
            foreach ($methods as $method) {
                $stubs[] = <<<STUB
                        public function $method(): void;
                    STUB;
            }
            $methodStubs = "\n" . implode("\n\n", $stubs) . "\n";
        }

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Contracts;

            use Pulsar\\Api\\Api;

            /**
             * Port interface for $name operations.
             */
            #[Api(since: '1.0.0')]
            interface $interfaceName
            {$methodStubs}
            PHP;
    }
}
