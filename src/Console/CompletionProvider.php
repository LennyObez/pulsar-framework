<?php

declare(strict_types=1);

namespace Pulsar\Console;

use NoDiscard;
use Pulsar\Api\Api;

use function array_filter;
use function array_keys;
use function array_values;
use function implode;
use function sprintf;
use function str_starts_with;
use function strlen;

/**
 * Shell completion script generator for bash, zsh, and fish.
 *
 * Generates shell-specific completion scripts that enable tab-completion
 * for Pulsar CLI commands, options, and arguments.
 * @api
 */
#[Api(since: '1.0.0')]
final class CompletionProvider
{
    /** @var list<string> */
    private readonly array $commandNames;

    /** @var array<string, list<string>> */
    private readonly array $commandOptions;

    /**
     * @param array<string, CommandInterface> $commands Registered commands
     * @param string $binaryName The CLI binary name (e.g. 'pulsar')
     */
    public function __construct(
        array $commands,
        private readonly string $binaryName = 'pulsar',
    ) {
        $names = [];
        $options = [];

        foreach ($commands as $name => $command) {
            $names[] = $name;

            $commandOpts = ['--help', '-h', '--quiet', '-q', '--verbose', '-v'];
            if ($command instanceof Command) {
                foreach (array_keys($command->options) as $optName) {
                    $commandOpts[] = '--' . $optName;
                }
            }
            $options[$name] = $commandOpts;
        }

        $this->commandNames = $names;
        $this->commandOptions = $options;
    }

    /**
     * Generate completion script for the specified shell.
     *
     * @param string $shell One of 'bash', 'zsh', 'fish'
     *
     * @return string The shell completion script
     */
    #[NoDiscard]
    public function generate(string $shell): string
    {
        return match ($shell) {
            'bash' => $this->generateBash(),
            'zsh' => $this->generateZsh(),
            'fish' => $this->generateFish(),
            default => sprintf('# Unsupported shell: %s. Supported: bash, zsh, fish', $shell),
        };
    }

    /**
     * Get completions for a partial input.
     *
     * @param string $current The current word being completed
     * @param string|null $commandContext The command context (if already parsed)
     *
     * @return list<string> Matching completions
     */
    #[NoDiscard]
    public function complete(string $current, ?string $commandContext = null): array
    {
        // Complete options within a command context
        if ($commandContext !== null && str_starts_with($current, '-')) {
            $options = $this->commandOptions[$commandContext] ?? [];

            return array_values(array_filter(
                $options,
                static fn(string $opt): bool => str_starts_with($opt, $current),
            ));
        }

        // Complete command names
        return array_values(array_filter(
            $this->commandNames,
            static fn(string $name): bool => str_starts_with($name, $current),
        ));
    }

    /**
     * Generate Bash completion script.
     */
    private function generateBash(): string
    {
        $commands = implode(' ', $this->commandNames);
        $binary = $this->binaryName;

        $caseBranches = '';
        foreach ($this->commandOptions as $cmdName => $options) {
            $optList = implode(' ', $options);
            $caseBranches .= sprintf("        %s)\n            COMPREPLY=(\$(compgen -W '%s': \"\${cur}\"))\n            return 0\n            ;;\n", $cmdName, $optList);
        }

        return <<<BASH
            # Bash completion for {$binary}
            # Add to ~/.bashrc or ~/.bash_completion
            _{$binary}_completions() {
                local cur prev commands
                COMPREPLY=()
                cur="\${COMP_WORDS[COMP_CWORD]}"
                prev="\${COMP_WORDS[COMP_CWORD-1]}"
                commands="{$commands}"

                if [[ \${COMP_CWORD} -eq 1 ]]; then
                    COMPREPLY=(\$(compgen -W "\${commands}" -- "\${cur}"))
                    return 0
                fi

                case "\${COMP_WORDS[1]}" in
            {$caseBranches}    esac
            }

            complete -F _{$binary}_completions {$binary}
            BASH;
    }

    /**
     * Generate Zsh completion script.
     */
    private function generateZsh(): string
    {
        $binary = $this->binaryName;

        $commandEntries = '';
        foreach ($this->commandNames as $name) {
            $commandEntries .= sprintf("    '%s'\n", $name);
        }

        $optionCases = '';
        foreach ($this->commandOptions as $cmdName => $options) {
            $optList = implode(' ', array_map(
                static fn(string $opt): string => sprintf("'%s'", $opt),
                $options,
            ));
            $optionCases .= sprintf("    %s)\n        _arguments %s\n        ;;\n", $cmdName, $optList);
        }

        return <<<ZSH
            #compdef {$binary}
            # Zsh completion for {$binary}
            # Add to a directory in your \$fpath

            _{$binary}() {
                local -a commands
                commands=(
            {$commandEntries}    )

                _arguments '1:command:(\${commands})' '*::arg:->args'

                case \$words[1] in
            {$optionCases}    esac
            }

            _{$binary}
            ZSH;
    }

    /**
     * Generate Fish completion script.
     */
    private function generateFish(): string
    {
        $binary = $this->binaryName;

        $lines = [
            sprintf('# Fish completion for %s', $binary),
            sprintf('# Save to ~/.config/fish/completions/%s.fish', $binary),
            '',
        ];

        // Command completions
        foreach ($this->commandNames as $name) {
            $lines[] = sprintf(
                "complete -c %s -n '__fish_use_subcommand' -a '%s'",
                $binary,
                $name,
            );
        }

        $lines[] = '';

        // Option completions per command
        foreach ($this->commandOptions as $cmdName => $options) {
            foreach ($options as $option) {
                $cleanOption = ltrim($option, '-');
                $short = strlen($cleanOption) === 1;

                if ($short) {
                    $lines[] = sprintf(
                        "complete -c %s -n '__fish_seen_subcommand_from %s' -s '%s'",
                        $binary,
                        $cmdName,
                        $cleanOption,
                    );
                } else {
                    $lines[] = sprintf(
                        "complete -c %s -n '__fish_seen_subcommand_from %s' -l '%s'",
                        $binary,
                        $cmdName,
                        $cleanOption,
                    );
                }
            }
        }

        return implode("\n", $lines) . "\n";
    }
}
