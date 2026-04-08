<?php

declare(strict_types=1);

namespace Psr\Http\Message;

/**
 * Typed PSR-7 ServerRequestInterface stub for Psalm static analysis.
 *
 * The PSR-7 vendor interface declares loose return types for backwards
 * compatibility with PHP 7:
 *  - `getParsedBody(): null|array|object`
 *  - `getQueryParams(): array`
 *  - `getServerParams(): array`
 *  - `getCookieParams(): array`
 *  - `getAttributes(): array`
 *  - `getUploadedFiles(): array`
 *
 * These widen the return surface so much that every `$body['foo']`
 * access flares MixedAssignment / MixedArrayAccess at call sites,
 * polluting Psalm's signal with hundreds of false positives.
 *
 * This stub re-declares the same methods with documented array shapes
 * (`array<array-key, mixed>` for return arrays) so Psalm can narrow
 * accesses without losing the truth that individual values are mixed.
 * It does NOT tighten the PSR contract — runtime behaviour is unchanged.
 */
interface ServerRequestInterface extends RequestInterface
{
    /**
     * @return array<array-key, mixed>
     */
    public function getServerParams(): array;

    /**
     * @return array<array-key, mixed>
     */
    public function getCookieParams(): array;

    /**
     * @return static
     */
    public function withCookieParams(array $cookies): ServerRequestInterface;

    /**
     * @return array<array-key, mixed>
     */
    public function getQueryParams(): array;

    /**
     * @return static
     */
    public function withQueryParams(array $query): ServerRequestInterface;

    /**
     * @return array<array-key, mixed>
     */
    public function getUploadedFiles(): array;

    /**
     * @return static
     */
    public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface;

    /**
     * @return array<array-key, mixed>|object|null
     */
    public function getParsedBody();

    /**
     * @param array<array-key, mixed>|object|null $data
     *
     * @return static
     */
    public function withParsedBody($data): ServerRequestInterface;

    /**
     * @return array<array-key, mixed>
     */
    public function getAttributes(): array;

    /**
     * @param mixed $default
     *
     * @return mixed
     */
    public function getAttribute(string $name, $default = null);

    /**
     * @param mixed $value
     *
     * @return static
     */
    public function withAttribute(string $name, $value): ServerRequestInterface;

    /**
     * @return static
     */
    public function withoutAttribute(string $name): ServerRequestInterface;
}
