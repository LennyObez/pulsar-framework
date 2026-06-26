<?php

declare(strict_types=1);

namespace PulsarAutoloadFixture;

/**
 * Autoload fixture for {@see \Pulsar\Tests\Unit\Extensibility\ExtensionAutoloaderTest}.
 *
 * Deliberately in a namespace NOT mapped by composer.json (root or autoload-dev),
 * so the test proves ExtensionAutoloader resolves it independently of Composer.
 */
final class Widget {}
