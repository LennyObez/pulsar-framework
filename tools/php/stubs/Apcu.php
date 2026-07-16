<?php

/**
 * Stub for the optional ext-apcu APCuIterator class.
 *
 * Psalm bundles the apcu_* function signatures but not the iterator class
 * declaration, so ApcuDriver::clearByPrefix() would report UndefinedClass on
 * hosts without the extension. The real class is provided by ext-apcu at
 * runtime; ApcuDriver is only ever built when the extension is loaded.
 *
 * Coverage is scoped to the symbols used by:
 *   src/Cache/Application/Driver/ApcuDriver.php
 */

class APCuIterator implements Iterator
{
    public function __construct(
        array|string|null $search = null,
        int $format = 4096,
        int $chunk_size = 100,
        int $list = 1,
    ) {}

    public function current(): mixed
    {
        return null;
    }

    public function key(): string|int
    {
        return '';
    }

    public function next(): void {}

    public function rewind(): void {}

    public function valid(): bool
    {
        return false;
    }
}
