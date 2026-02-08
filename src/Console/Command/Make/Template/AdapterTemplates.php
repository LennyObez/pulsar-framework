<?php

declare(strict_types=1);

namespace Pulsar\Console\Command\Make\Template;

use Pulsar\Console\Command\Make\MethodSignature;

/**
 * Template generator for make:adapter scaffolding.
 */
final readonly class AdapterTemplates
{
    /**
     * @param list<MethodSignature> $methods
     */
    public function adapter(string $name, string $namespace, string $portName, array $methods): string
    {
        $implements = $portName . 'Interface';
        $methodBodies = '';

        if ($methods !== []) {
            $stubs = [];
            foreach ($methods as $method) {
                $params = [];
                foreach ($method->parameters as $param) {
                    $paramStr = '';
                    if ($param['type'] !== '') {
                        $paramStr .= $param['type'] . ' ';
                    }
                    $paramStr .= $param['name'];
                    if ($param['default'] !== null) {
                        $paramStr .= ' = ' . $param['default'];
                    }
                    $params[] = $paramStr;
                }

                $paramList = implode(', ', $params);
                $returnType = $method->returnType !== '' ? ': ' . $method->returnType : '';

                $stubs[] = <<<STUB
                        #[Override]
                        public function $method->name($paramList)$returnType
                        {
                            throw new \LogicException('Not implemented.');
                        }
                    STUB;
            }
            $methodBodies = "\n" . implode("\n\n", $stubs) . "\n";
        }

        $useOverride = $methods !== [] ? "\nuse Override;" : '';
        $classBody = '{' . $methodBodies . '}';

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace $namespace\\Internal\\Infrastructure;
            $useOverride
            use $namespace\\Contracts\\$implements;

            /**
             * $name adapter implementing $implements.
             */
            final readonly class $name implements $implements
            $classBody
            PHP;
    }

    public function adapterTest(string $name, string $module, string $namespace, string $portName): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace Pulsar\\Tests\\Unit\\Modules\\$module\\Internal\\Infrastructure;

            use PHPUnit\\Framework\\Attributes\\CoversClass;
            use PHPUnit\\Framework\\Attributes\\Test;
            use PHPUnit\\Framework\\TestCase;
            use $namespace\\Contracts\\{$portName}Interface;
            use $namespace\\Internal\\Infrastructure\\$name;

            #[CoversClass($name::class)]
            final class {$name}Test extends TestCase
            {
                #[Test]
                public function it_implements_the_port_interface(): void
                {
                    \$adapter = new $name();

                    self::assertInstanceOf({$portName}Interface::class, \$adapter);
                }
            }
            PHP;
    }
}
