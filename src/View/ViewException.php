<?php

declare(strict_types=1);

namespace Pulsar\View;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Exception thrown by the View module.
 *
 * Uses static factory methods for each failure scenario.
 */
#[Api(since: '1.0.0')]
class ViewException extends RuntimeException
{
    #[NoDiscard]
    public static function templateNotFound(string $name, string $searchedPaths): self
    {
        return new self(sprintf(
            'Template "%s" not found. Searched paths: %s',
            $name,
            $searchedPaths,
        ));
    }

    #[NoDiscard]
    public static function compilationFailed(string $template, string $reason): self
    {
        return new self(sprintf(
            'Failed to compile template "%s": %s',
            $template,
            $reason,
        ));
    }

    #[NoDiscard]
    public static function cacheWriteFailed(string $path): self
    {
        return new self(sprintf(
            'Failed to write compiled template cache to "%s"',
            $path,
        ));
    }

    #[NoDiscard]
    public static function phpDirectiveDisabled(string $template): self
    {
        return new self(sprintf(
            'The @php directive is disabled in the current environment. Found in template "%s". '
            . 'Set ViewConfig::phpDirectiveAllowed to true to enable.',
            $template,
        ));
    }

    #[NoDiscard]
    public static function sandboxStepLimitExceeded(int $limit): self
    {
        return new self(sprintf(
            'Untrusted template exceeded the step limit of %d AST node evaluations',
            $limit,
        ));
    }

    #[NoDiscard]
    public static function sandboxLoopLimitExceeded(int $limit): self
    {
        return new self(sprintf(
            'Untrusted template exceeded the loop iteration limit of %d',
            $limit,
        ));
    }

    #[NoDiscard]
    public static function sandboxOutputSizeLimitExceeded(int $limit): self
    {
        return new self(sprintf(
            'Untrusted template exceeded the output size limit of %d bytes',
            $limit,
        ));
    }

    #[NoDiscard]
    public static function sandboxWallClockExceeded(): self
    {
        return new self('Untrusted template execution exceeded the wall-clock time limit');
    }

    #[NoDiscard]
    public static function rawOutputInUntrustedMode(): self
    {
        return new self('Raw unescaped output ({!! !!}) is not permitted in untrusted template mode');
    }

    #[NoDiscard]
    public static function invalidDirective(string $directive, string $reason): self
    {
        return new self(sprintf(
            'Invalid directive @%s: %s',
            $directive,
            $reason,
        ));
    }

    #[NoDiscard]
    public static function sandboxViolation(string $template, string $symbol, string $kind): self
    {
        return new self(sprintf(
            'Sandbox violation in template "%s": %s "%s" is not allowed',
            $template,
            $kind,
            $symbol,
        ));
    }

    #[NoDiscard]
    public static function typedTemplateViolation(string $template, string $variable, string $expected, string $actual): self
    {
        return new self(sprintf(
            'Type mismatch in template "%s": variable "$%s" expected %s, got %s',
            $template,
            $variable,
            $expected,
            $actual,
        ));
    }

    #[NoDiscard]
    public static function circularInheritance(string $template, string $parent): self
    {
        return new self(sprintf(
            'Circular template inheritance detected: "%s" extends "%s" which forms a cycle',
            $template,
            $parent,
        ));
    }

    #[NoDiscard]
    public static function undefinedSection(string $section, string $template): self
    {
        return new self(sprintf(
            'Section "%s" is not defined in template "%s"',
            $section,
            $template,
        ));
    }

    #[NoDiscard]
    public static function includeNotAllowed(string $templateId): self
    {
        return new self(sprintf(
            'Template ID "%s" is not in the sandbox include allowlist',
            $templateId,
        ));
    }
}
