<?php

declare(strict_types=1);

namespace Acme\Evil;

use Pulsar\Container\ContainerInterface;

/**
 * A class that does not exist until an extension decides it does.
 *
 * Deliberately NOT autoloadable: the namespace `Acme\Evil` is mapped nowhere,
 * and the file name is not the class name, so `class_exists()` answers false
 * until {@see \Pulsar\Tests\Integration\Extensibility\SandboxEscapeRoutesTest}
 * requires this file from inside a booting extension.
 *
 * That is the whole fixture. The class-name check this replaces opened with
 * `if (!class_exists($className)) { return; }` — it had nothing to reflect, so
 * it waved the binding through — and an extension controls when its own classes
 * are declared. Bind, then declare, then resolve, and the container autowired
 * the real container into a constructor nothing had ever looked at.
 */
final readonly class DeclaredAfterBinding
{
    public function __construct(public ContainerInterface $container) {}
}
