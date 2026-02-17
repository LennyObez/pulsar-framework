<?php

declare(strict_types=1);

namespace Pulsar\Console;

use Pulsar\Api\Api;

use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function fgets;
use function function_exists;
use function in_array;
use function is_resource;
use function max;
use function sprintf;
use function str_contains;
use function strtolower;
use function trim;

/**
 * Interactive CLI prompts for user input.
 *
 * Provides text input, confirmations, select menus, multi-select,
 * password input, and search-as-you-type prompts.
 */
#[Api(since: '1.0.0')]
final class InteractivePrompt
{
    /** @var resource */
    private $inputStream;

    /**
     * @param resource|null $inputStream Stream to read input from (defaults to STDIN)
     */
    public function __construct(
        private readonly OutputInterface $output,
        mixed $inputStream = null,
    ) {
        $this->inputStream = $inputStream ?? STDIN;
    }

    /**
     * Prompt for free-form text input.
     *
     * @param string $question The prompt message
     * @param string|null $default Default value if user presses Enter
     * @param (callable(string): ?string)|null $validator Returns error message or null
     */
    public function text(string $question, ?string $default = null, ?callable $validator = null): string
    {
        $suffix = $default !== null ? sprintf(' [%s]', $default) : '';
        $this->output->write(sprintf('%s%s: ', $question, $suffix));

        while (true) {
            $answer = $this->readLine();

            if ($answer === '' && $default !== null) {
                return $default;
            }

            if ($validator !== null) {
                $error = $validator($answer);
                if ($error !== null) {
                    $this->output->error($error);
                    $this->output->newLine();
                    $this->output->write(sprintf('%s%s: ', $question, $suffix));
                    continue;
                }
            }

            return $answer;
        }
    }

    /**
     * Prompt for a yes/no confirmation.
     *
     * @param string $question The prompt message
     * @param bool $default Default value (true = yes, false = no)
     */
    public function confirm(string $question, bool $default = false): bool
    {
        $suffix = $default ? ' [Y/n]' : ' [y/N]';
        $this->output->write(sprintf('%s%s: ', $question, $suffix));

        $answer = strtolower(trim($this->readLine()));

        if ($answer === '') {
            return $default;
        }

        return in_array($answer, ['y', 'yes', '1', 'true'], true);
    }

    /**
     * Prompt to select a single option from a list.
     *
     * @param string $question The prompt message
     * @param list<string> $choices Available choices
     * @param int|null $default Default choice index (0-based)
     *
     * @return string The selected choice value
     */
    public function select(string $question, array $choices, ?int $default = null): string
    {
        $this->output->writeln($question);

        foreach ($choices as $index => $choice) {
            $marker = ($default !== null && $index === $default) ? '>' : ' ';
            $this->output->writeln(sprintf('  %s [%d] %s', $marker, $index, $choice));
        }

        $defaultSuffix = $default !== null ? sprintf(' [%d]', $default) : '';
        $this->output->write(sprintf('Choose%s: ', $defaultSuffix));

        while (true) {
            $answer = trim($this->readLine());

            if ($answer === '' && $default !== null) {
                return $choices[$default];
            }

            if (is_numeric($answer)) {
                $index = (int) $answer;
                if (isset($choices[$index])) {
                    return $choices[$index];
                }
            }

            $this->output->error(sprintf('Invalid selection "%s". Please enter a number between 0 and %d.', $answer, count($choices) - 1));
            $this->output->newLine();
            $this->output->write(sprintf('Choose%s: ', $defaultSuffix));
        }
    }

    /**
     * Prompt to select multiple options from a list.
     *
     * @param string $question The prompt message
     * @param list<string> $choices Available choices
     * @param list<int> $defaults Default selected indices
     *
     * @return list<string> The selected choice values
     */
    public function multiselect(string $question, array $choices, array $defaults = []): array
    {
        $this->output->writeln($question);
        $this->output->writeln('  (Enter comma-separated numbers, e.g. 0,2,3)');

        foreach ($choices as $index => $choice) {
            $marker = in_array($index, $defaults, true) ? '[x]' : '[ ]';
            $this->output->writeln(sprintf('  %s %d. %s', $marker, $index, $choice));
        }

        $defaultSuffix = $defaults !== [] ? sprintf(' [%s]', implode(',', $defaults)) : '';
        $this->output->write(sprintf('Select%s: ', $defaultSuffix));

        while (true) {
            $answer = trim($this->readLine());

            if ($answer === '' && $defaults !== []) {
                $result = [];
                foreach ($defaults as $i) {
                    if (isset($choices[$i])) {
                        $result[] = $choices[$i];
                    }
                }
                return $result;
            }

            $indices = array_map('intval', explode(',', $answer));
            $valid = true;
            $result = [];

            foreach ($indices as $i) {
                if (!isset($choices[$i])) {
                    $valid = false;
                    break;
                }
                $result[] = $choices[$i];
            }

            if ($valid && $result !== []) {
                return $result;
            }

            $this->output->error(sprintf('Invalid selection. Enter numbers between 0 and %d, separated by commas.', count($choices) - 1));
            $this->output->newLine();
            $this->output->write(sprintf('Select%s: ', $defaultSuffix));
        }
    }

    /**
     * Prompt for a password (hidden input).
     *
     * Falls back to visible input if the terminal does not support hiding.
     *
     * @param string $question The prompt message
     */
    public function password(string $question = 'Password'): string
    {
        // On Unix with proper TTY, attempt hidden input via stty
        if (DIRECTORY_SEPARATOR !== '\\' && $this->isInteractiveTty()) {
            $this->output->write(sprintf('%s: ', $question));

            $this->disableTerminalEcho();
            $password = $this->readLine();
            $this->enableTerminalEcho();

            $this->output->newLine();

            return $password;
        }

        // Fallback: just prompt normally (input will be visible)
        $this->output->write(sprintf('%s (input will be visible): ', $question));

        return $this->readLine();
    }

    /**
     * Search-as-you-type prompt.
     *
     * In non-interactive mode, falls back to a simple text filter.
     *
     * @param string $question The prompt message
     * @param list<string> $choices The full set of choices to search through
     * @param int $maxResults Maximum results to display
     *
     * @return string The selected result
     */
    public function search(string $question, array $choices, int $maxResults = 10): string
    {
        $this->output->writeln($question);
        $this->output->write('Search: ');

        $query = trim($this->readLine());

        $filtered = $query === ''
            ? $choices
            : array_values(array_filter(
                $choices,
                static fn(string $choice): bool => str_contains(strtolower($choice), strtolower($query)),
            ));

        if ($filtered === []) {
            $this->output->warning('No results found.');
            return '';
        }

        $display = array_slice($filtered, 0, max(1, $maxResults));

        return $this->select('Results:', $display, 0);
    }

    /**
     * Read a line from the input stream.
     */
    private function readLine(): string
    {
        $line = fgets($this->inputStream);

        if ($line === false) {
            return '';
        }

        return trim($line, "\n\r");
    }

    /**
     * Check if the input stream is an interactive terminal.
     */
    private function isInteractiveTty(): bool
    {
        if (!function_exists('posix_isatty')) {
            return false;
        }

        return @posix_isatty($this->inputStream);
    }

    /**
     * Disable terminal echo for password input.
     *
     * Calls stty with a fully constant command literal.
     */
    private function disableTerminalEcho(): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open('stty -echo', $descriptors, $pipes);

        if (is_resource($process)) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    /**
     * Re-enable terminal echo after password input.
     *
     * Calls stty with a fully constant command literal.
     */
    private function enableTerminalEcho(): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open('stty echo', $descriptors, $pipes);

        if (is_resource($process)) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }
}
