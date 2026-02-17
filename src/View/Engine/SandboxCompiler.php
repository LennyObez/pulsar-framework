<?php

declare(strict_types=1);

namespace Pulsar\View\Engine;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\View\ViewException;

use function array_diff;
use function array_unique;
use function array_values;
use function in_array;
use function preg_match_all;
use function strtolower;

/**
 * Post-compilation validator that rejects compiled PHP containing disallowed functions or classes.
 *
 * Scans compiled template output for function calls and class references, rejecting
 * any that appear on the deny list. Runs at compile time so that violations are caught
 * before any template code executes.
 */
#[Internal(reason: 'Sandbox validation is an engine implementation detail')]
final readonly class SandboxCompiler
{
    /** Functions that are never allowed in sandboxed templates. */
    private const array DENIED_FUNCTIONS = [
        // Process execution
        'exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open', 'pcntl_exec',
        // File system (write/delete)
        'file_put_contents', 'fwrite', 'fputs', 'fopen', 'unlink', 'rmdir', 'mkdir',
        'rename', 'copy', 'chmod', 'chown', 'chgrp', 'symlink', 'link', 'tempnam',
        'tmpfile',
        // File system (read; sensitive)
        'file_get_contents', 'file', 'readfile', 'fgets', 'fread', 'fgetcsv',
        // Network
        'curl_init', 'curl_exec', 'fsockopen', 'stream_socket_client', 'socket_create',
        'dns_get_record', 'gethostbyname',
        // Code execution
        'eval', 'assert', 'create_function', 'call_user_func', 'call_user_func_array',
        'preg_replace_callback_array',
        // Variable manipulation (dangerous)
        'extract', 'compact', 'parse_str',
        // Include/require
        'include', 'include_once', 'require', 'require_once',
        // Serialization (deserialization attacks)
        'unserialize',
        // Info disclosure
        'phpinfo', 'php_uname', 'getmypid', 'get_current_user',
        'getenv', 'putenv', 'ini_set', 'ini_get', 'ini_restore',
        // Database (direct)
        'sqlite_open', 'sqlite_query', 'pg_connect', 'pg_query',
        'mysqli_connect', 'mysqli_query',
        // Mail
        'mail',
        // Shutdown/exit
        'exit', 'die',
        // Class manipulation
        'class_alias',
    ];

    /** Classes that are never allowed in sandboxed templates. */
    private const array DENIED_CLASSES = [
        'PDO', 'PDOStatement', 'SQLite3',
        'mysqli', 'mysqli_result', 'mysqli_stmt',
        'ReflectionClass', 'ReflectionMethod', 'ReflectionFunction', 'ReflectionProperty',
        'SplFileObject', 'SplFileInfo', 'DirectoryIterator', 'RecursiveDirectoryIterator',
        'GlobIterator', 'FilesystemIterator',
        'CurlHandle', 'CurlMultiHandle',
        'Fiber',
    ];

    /** @var list<string> */
    private array $allowedFunctions;

    /** @var list<string> */
    private array $allowedClasses;

    /**
     * @param list<string> $additionalAllowedFunctions Extra functions to permit beyond the defaults
     * @param list<string> $additionalAllowedClasses Extra classes to permit beyond the defaults
     */
    public function __construct(
        array $additionalAllowedFunctions = [],
        array $additionalAllowedClasses = [],
    ) {
        $this->allowedFunctions = $additionalAllowedFunctions;
        $this->allowedClasses = $additionalAllowedClasses;
    }

    /**
     * Validate compiled PHP output against the sandbox deny lists.
     *
     * @param string $compiledOutput The compiled PHP code to validate
     * @param string $templateName Template name for error context
     *
     * @throws ViewException If a denied function or class is found
     */
    public function validate(string $compiledOutput, string $templateName): void
    {
        $this->validateFunctions($compiledOutput, $templateName);
        $this->validateClasses($compiledOutput, $templateName);
    }

    /**
     * Detect denied function calls in compiled output.
     *
     * @throws ViewException If a denied function call is found
     */
    private function validateFunctions(string $compiledOutput, string $templateName): void
    {
        // Match function calls: name(
        if (preg_match_all('/\b([a-zA-Z_]\w*)\s*\(/', $compiledOutput, $matches) === 0) {
            return;
        }

        $calledFunctions = array_values(array_unique($matches[1]));

        foreach ($calledFunctions as $function) {
            $lower = strtolower($function);

            if (in_array($lower, self::DENIED_FUNCTIONS, true) && !$this->isFunctionAllowed($lower)) {
                throw ViewException::sandboxViolation($templateName, $function, 'function');
            }
        }
    }

    /**
     * Detect denied class references in compiled output.
     *
     * @throws ViewException If a denied class reference is found
     */
    private function validateClasses(string $compiledOutput, string $templateName): void
    {
        // Match: new ClassName, ClassName::, ClassName $var
        if (preg_match_all('/\bnew\s+([A-Z]\w*)|([A-Z]\w*)\s*::/', $compiledOutput, $matches) === 0) {
            return;
        }

        $referencedClasses = [];

        foreach ($matches[1] as $match) {
            if ($match !== '') {
                $referencedClasses[] = $match;
            }
        }

        foreach ($matches[2] as $match) {
            if ($match !== '') {
                $referencedClasses[] = $match;
            }
        }

        $referencedClasses = array_values(array_unique($referencedClasses));

        foreach ($referencedClasses as $class) {
            if (in_array($class, self::DENIED_CLASSES, true) && !$this->isClassAllowed($class)) {
                throw ViewException::sandboxViolation($templateName, $class, 'class');
            }
        }
    }

    /**
     * Check whether a function has been explicitly allowed.
     */
    #[NoDiscard]
    private function isFunctionAllowed(string $function): bool
    {
        return in_array($function, $this->allowedFunctions, true);
    }

    /**
     * Check whether a class has been explicitly allowed.
     */
    #[NoDiscard]
    private function isClassAllowed(string $class): bool
    {
        return in_array($class, $this->allowedClasses, true);
    }

    /**
     * Get the list of denied functions.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function deniedFunctions(): array
    {
        return array_values(array_diff(self::DENIED_FUNCTIONS, $this->allowedFunctions));
    }

    /**
     * Get the list of denied classes.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function deniedClasses(): array
    {
        return array_values(array_diff(self::DENIED_CLASSES, $this->allowedClasses));
    }
}
