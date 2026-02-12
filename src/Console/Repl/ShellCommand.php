<?php

declare(strict_types=1);

namespace Pulsar\Console\Repl;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Config\ReplConfig;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Container\ContainerInterface;

use function class_exists;
use function implode;
use function is_int;
use function is_string;
use function microtime;
use function sprintf;

/**
 * Interactive REPL command with framework context.
 *
 * Starts a PsySH-based shell with the application container, safe-mode
 * wrappers, environment guards, audit logging, and secret redaction.
 */
#[Internal]
final class ShellCommand extends Command
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly EnvironmentMode $mode,
        private readonly EnvironmentGuard $guard,
        private readonly ReplConfig $config,
        private readonly ?ReplAuditLogger $auditLogger = null,
        private readonly ?SecretRedactor $redactor = null,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'shell';
        $this->description = 'Start an interactive REPL with framework context';
        $this->addOption('no-safe-mode', 'Disable safe mode (allow mutations)');
        $this->addOption('i-know-what-im-doing', 'Required flag for production REPL access');
        $this->addOption('no-audit', 'Disable audit logging for this session');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        // Check PsySH availability (suggested dependency)
        if (!class_exists('Psy\\Shell')) {
            $output->errorln('PsySH is not installed. Install it with:');
            $output->errorln('  composer require --dev psy/psysh');
            return ExitCode::Error->value;
        }

        // Environment guard check
        $forceFlag = $input->hasOption('i-know-what-im-doing');
        $guardResult = $this->guard->canStart($forceFlag);

        if (!$guardResult->allowed) {
            $output->errorln($guardResult->reason);
            return ExitCode::Error->value;
        }

        // Determine safe mode
        $safeMode = $this->config->safeMode && !$input->hasOption('no-safe-mode');

        // Apply safe mode wrappers
        if ($safeMode) {
            $safeModeProvider = new SafeModeProvider($this->container);
            $safeModeProvider->apply();
        }

        // Determine audit state — production always requires audit logging
        $auditEnabled = $this->config->audit && !$input->hasOption('no-audit');
        $actor = $this->resolveActor();

        if ($this->mode === EnvironmentMode::Production && !$auditEnabled) {
            $auditEnabled = true;
            $output->warning('Audit logging cannot be disabled in production. --no-audit ignored.');
        }

        // Log production override if applicable
        if ($guardResult->isProductionOverride && $auditEnabled && $this->auditLogger !== null) {
            $this->auditLogger->logProductionOverride($actor);
        }

        // Log session start
        if ($auditEnabled && $this->auditLogger !== null) {
            $this->auditLogger->logSessionStart($actor, $this->mode, $safeMode);
        }

        $startTime = microtime(true);

        // Display banner
        $modeLabel = $safeMode ? 'safe mode' : 'unrestricted';
        $output->info(sprintf('Pulsar REPL (%s, %s)', $this->mode->value, $modeLabel));

        if ($safeMode) {
            $output->info('Safe mode active: database writes, queue dispatch, storage writes, and cache writes are blocked.');
        }

        $output->writeln('Type "exit" or press Ctrl+D to quit.');
        $output->newLine();

        // Build scope variables — expose container (read-only in safe mode) and redactor
        $exposedContainer = $safeMode ? new ReadOnlyContainer($this->container) : $this->container;
        $scopeVars = ['container' => $exposedContainer];
        if ($this->redactor !== null) {
            $scopeVars['redactor'] = $this->redactor;
            $output->writeln('$redactor available — use $redactor->redactOutput($string) to scrub secrets.');
            $output->newLine();
        }

        // Run PsySH (suggested dependency — dynamically resolved to avoid compile-time coupling)
        try {
            $exitCode = $this->runPsyShell($scopeVars);
        } finally {
            // Log session end (always, even if shell throws)
            if ($auditEnabled && $this->auditLogger !== null) {
                $duration = microtime(true) - $startTime;
                // Command count unavailable without PsySH instrumentation
                $this->auditLogger->logSessionEnd($actor, null, $duration);
            }
        }

        return $exitCode;
    }

    /**
     * Configure and run the PsySH shell.
     *
     * PsySH is a suggested dependency — string-based dynamic instantiation
     * avoids compile-time coupling. class_exists() is checked before this
     * method is called.
     *
     * @param array<string, mixed> $scopeVars
     */
    private function runPsyShell(array $scopeVars): int
    {
        /** @var class-string<\Psy\Configuration> $configClass */
        $configClass = 'Psy\\Configuration';
        /** @var class-string<\Psy\Shell> $shellClass */
        $shellClass = 'Psy\\Shell';

        $psyConfig = new $configClass([
            'updateCheck' => 'never',
            'usePcntl' => false,
            'historyFile' => $this->config->historyFile,
        ]);

        $shell = new $shellClass($psyConfig);
        $shell->setScopeVariables($scopeVars);

        if ($this->config->startupCommands !== []) {
            $shell->addInput(implode("\n", $this->config->startupCommands));
        }

        /** @var mixed $result */
        $result = $shell->run();

        return is_int($result) ? $result : ExitCode::Success->value;
    }

    /**
     * Resolve the current actor identity for audit logging.
     */
    private function resolveActor(): string
    {
        // Use OS username as actor identity
        $user = $_SERVER['USER'] ?? $_SERVER['USERNAME'] ?? null;

        return is_string($user) ? $user : 'unknown';
    }
}
