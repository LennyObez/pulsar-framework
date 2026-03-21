<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Api\Api;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;

use function dirname;
use function escapeshellarg;
use function file_exists;
use function filter_var;
use function implode;
use function in_array;
use function is_dir;
use function is_int;
use function is_resource;
use function is_string;
use function preg_match;
use function proc_close;
use function proc_open;
use function sprintf;

use const DIRECTORY_SEPARATOR;
use const FILTER_VALIDATE_IP;
use const PHP_BINARY;
use const STDERR;
use const STDIN;
use const STDOUT;

/**
 * Start PHP's built-in development server for the project.
 *
 * Validates options, resolves the document root and router script,
 * then starts the server via proc_open with an explicit argument array
 * (no shell interpretation). Stdin/stdout/stderr are inherited so server
 * output streams directly to the terminal.
 *
 * For production, use `runtime:serve` with an async runtime instead.
 * @api
 */
#[Api(since: '1.0.0')]
final class ServeCommand extends Command
{
    public function __construct(
        private readonly string $basePath,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'serve';
        $this->description = 'Start the PHP built-in development server';

        $this->addOption('host', 'Host address to bind to', 'H');
        $this->addOption('port', 'Port to listen on', 'p');
        $this->addOption('docroot', 'Document root directory (default: public/)');
        $this->addOption('public', 'Allow binding to non-loopback addresses');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = $this->resolveHost($input);
        $port = $this->resolvePort($input);

        // Validate host: must be a valid IP or safe hostname
        if (!self::isValidHost($host)) {
            $output->error(sprintf('Invalid host: "%s" (must be a valid IP address or hostname)', $host));

            return ExitCode::Error->value;
        }

        // Validate port range
        if ($port < 1 || $port > 65535) {
            $output->error(sprintf('Invalid port: %d (must be 1-65535)', $port));

            return ExitCode::Error->value;
        }

        // Validate host binding policy
        if (!$this->validateHostPolicy($host, $input->hasOption('public'), $output)) {
            return ExitCode::Error->value;
        }

        // Resolve document root
        $docroot = $this->resolveDocroot($input);

        // Publish framework assets (symlinks) before starting the server.
        // The PHP built-in server doesn't route all MIME types through the
        // router script, so static assets must exist in the document root.
        $this->publishAssets($docroot, $output);

        // Look for a router script: prefer router.php (handles all requests
        // including extensionless paths), fall back to index.php
        $routerScript = $docroot . DIRECTORY_SEPARATOR . 'router.php';

        if (!file_exists($routerScript)) {
            $routerScript = $docroot . DIRECTORY_SEPARATOR . 'index.php';
        }

        $hasRouter = file_exists($routerScript);

        // Build argument list (no shell interpretation; proc_open array form)
        $argv = [
            PHP_BINARY,
            '-S',
            sprintf('%s:%d', $host, $port),
            '-t',
            $docroot,
        ];

        if ($hasRouter) {
            $argv[] = $routerScript;
        }

        $output->info(sprintf('Pulsar development server: http://%s:%d', $host, $port));
        $output->writeln(sprintf('  Document root: %s', $docroot));
        $output->writeln('Press Ctrl+C to stop.');
        $output->newLine();

        // nosemgrep: php.lang.security.exec-use.exec-use: array form bypasses the shell entirely
        $process = proc_open($argv, [STDIN, STDOUT, STDERR], $pipes);

        if (!is_resource($process)) {
            $output->error('Failed to start the development server process.');

            return ExitCode::Error->value;
        }

        $exitCode = proc_close($process);

        return $exitCode === 0 ? ExitCode::Success->value : ExitCode::Error->value;
    }

    /**
     * Build the shell command string for starting the server.
     *
     * Exposed for testing and for callers that want the command
     * without executing it (e.g., scripts, CI).
     *
     * @return string Shell-escaped command ready to run
     */
    public function buildCommand(string $host, int $port, string $docroot, ?string $routerScript = null): string
    {
        $parts = [
            escapeshellarg(PHP_BINARY),
            '-S',
            escapeshellarg(sprintf('%s:%d', $host, $port)),
            '-t',
            escapeshellarg($docroot),
        ];

        if ($routerScript !== null) {
            $parts[] = escapeshellarg($routerScript);
        }

        return implode(' ', $parts);
    }

    private function resolveHost(InputInterface $input): string
    {
        $hostOption = $input->getOption('host');

        return $input->hasOption('host') && is_string($hostOption)
            ? $hostOption
            : '127.0.0.1';
    }

    private function resolvePort(InputInterface $input): int
    {
        $portOption = $input->getOption('port');

        return $input->hasOption('port') && (is_int($portOption) || is_string($portOption))
            ? (int) $portOption
            : 8000;
    }

    private function resolveDocroot(InputInterface $input): string
    {
        $docrootOption = $input->getOption('docroot');
        $docroot = $input->hasOption('docroot') && is_string($docrootOption)
            ? $docrootOption
            : $this->basePath . DIRECTORY_SEPARATOR . 'public';

        if (!is_dir($docroot)) {
            // Fall back to project root if no public/ directory exists
            $docroot = $this->basePath;
        }

        return $docroot;
    }

    /**
     * Validate that the host string is a safe IP address or hostname.
     *
     * Accepts IPv4, IPv6, and RFC 952 hostnames only.
     */
    private static function isValidHost(string $host): bool
    {
        // Valid IP address (v4 or v6)
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        // Valid hostname: alphanumeric, hyphens, dots: no shell metacharacters
        return preg_match('/\A[a-zA-Z0-9]([a-zA-Z0-9.\-]*[a-zA-Z0-9])?\z/', $host) === 1;
    }

    private function validateHostPolicy(string $host, bool $isPublic, OutputInterface $output): bool
    {
        $loopbackEquivalents = ['127.0.0.1', '::1', 'localhost', 'localhost.'];

        if (in_array($host, $loopbackEquivalents, true)) {
            return true;
        }

        if (!$isPublic) {
            $output->error(sprintf(
                'Binding to "%s" requires the --public flag. '
                . 'This server is not suitable for production. Use --public to acknowledge '
                . 'binding to a non-loopback address.',
                $host,
            ));

            return false;
        }

        return true;
    }

    /**
     * Create symlinks for framework UI assets in the public directory.
     *
     * The PHP built-in development server does not route all MIME types
     * (e.g., .woff2, .svg) through the router script. Symlinks ensure
     * these files are served directly from the document root.
     *
     * In production, the web server (Nginx/Apache) should serve the
     * framework's resources/ui/ directory at /ui/ via an alias directive.
     */
    private function publishAssets(string $docroot, OutputInterface $output): void
    {
        $frameworkRoot = dirname(__DIR__, 3);
        $uiSource = $frameworkRoot . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'ui';

        if (!is_dir($uiSource)) {
            return;
        }

        $uiTarget = $docroot . DIRECTORY_SEPARATOR . 'ui';

        // Skip if already linked or exists
        if (file_exists($uiTarget)) {
            return;
        }

        // Create the symlink
        if (@symlink($uiSource, $uiTarget)) {
            $output->writeln('  Published: /ui/ -> resources/ui/');
        }

        // CMS extension assets
        $cmsSource = $frameworkRoot . DIRECTORY_SEPARATOR . 'extensions' . DIRECTORY_SEPARATOR . 'cms' . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR . 'styles';
        $cmsTarget = $docroot . DIRECTORY_SEPARATOR . 'cms' . DIRECTORY_SEPARATOR . 'assets';

        if (is_dir($cmsSource) && !file_exists($cmsTarget)) {
            @mkdir(dirname($cmsTarget), 0o750, true);

            if (@symlink($cmsSource, $cmsTarget)) {
                $output->writeln('  Published: /cms/assets/ -> extensions/cms/frontend/styles/');
            }
        }
    }
}
