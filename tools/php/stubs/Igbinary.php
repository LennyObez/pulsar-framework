<?php

/**
 * Stub for the optional ext-igbinary serialization extension.
 *
 * Provides type declarations for static analysis so the result is identical
 * whether or not the extension is loaded. The real functions are provided by
 * ext-igbinary at runtime; the source always guards usage behind
 * extension_loaded()/function_exists() or a boot-time configuration check.
 *
 * Coverage is scoped to the symbols used by:
 *   src/Cache/Application/Serializer/IgbinaryCacheSerializer.php
 */

function igbinary_serialize(mixed $value): ?string
{
    return null;
}

function igbinary_unserialize(string $str): mixed
{
    return null;
}
