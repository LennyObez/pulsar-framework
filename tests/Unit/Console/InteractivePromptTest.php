<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\InteractivePrompt;
use Pulsar\Console\Output\BufferedOutput;

use function function_exists;

#[CoversClass(InteractivePrompt::class)]
final class InteractivePromptTest extends TestCase
{
    /**
     * Create an InteractivePrompt with simulated stdin.
     *
     * @param string $input Simulated user input (newline-terminated lines)
     * @return array{InteractivePrompt, BufferedOutput, resource}
     */
    private function createPrompt(string $input): array
    {
        $output = new BufferedOutput();
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        fwrite($stream, $input);
        rewind($stream);

        return [new InteractivePrompt($output, $stream), $output, $stream];
    }

    // --- text() ---

    #[Test]
    public function textReturnsUserInput(): void
    {
        [$prompt, $output, $stream] = $this->createPrompt("John\n");

        $result = $prompt->text('Name');

        self::assertSame('John', $result);
        self::assertStringContainsString('Name', $output->buffer);
        fclose($stream);
    }

    #[Test]
    public function textReturnsDefaultOnEmptyInput(): void
    {
        [$prompt, $output, $stream] = $this->createPrompt("\n");

        $result = $prompt->text('Name', 'Default');

        self::assertSame('Default', $result);
        self::assertStringContainsString('[Default]', $output->buffer);
        fclose($stream);
    }

    #[Test]
    public function textValidatesInputAndRepromptsOnFailure(): void
    {
        // First input fails validation, second succeeds
        [$prompt, $output, $stream] = $this->createPrompt("\nhello\n");

        $validator = static fn(string $value): ?string => $value === '' ? 'Required' : null;

        $result = $prompt->text('Name', null, $validator);

        self::assertSame('hello', $result);
        self::assertStringContainsString('Required', $output->errorBuffer);
        fclose($stream);
    }

    #[Test]
    public function textAcceptsEmptyInputWhenNoDefault(): void
    {
        [$prompt, $output, $stream] = $this->createPrompt("\n");

        $result = $prompt->text('Optional');

        self::assertSame('', $result);
        fclose($stream);
    }

    // --- confirm() ---

    #[Test]
    public function confirmReturnsTrueForYes(): void
    {
        [$prompt, $output, $stream] = $this->createPrompt("y\n");

        self::assertTrue($prompt->confirm('Continue?'));
        fclose($stream);
    }

    #[Test]
    public function confirmReturnsTrueForFullYes(): void
    {
        [$prompt, $output, $stream] = $this->createPrompt("yes\n");

        self::assertTrue($prompt->confirm('Continue?'));
        fclose($stream);
    }

    #[Test]
    public function confirmReturnsFalseForNo(): void
    {
        [$prompt, $output, $stream] = $this->createPrompt("n\n");

        self::assertFalse($prompt->confirm('Continue?'));
        fclose($stream);
    }

    #[Test]
    public function confirmReturnsFalseForArbitraryInput(): void
    {
        [$prompt, $output, $stream] = $this->createPrompt("maybe\n");

        self::assertFalse($prompt->confirm('Continue?'));
        fclose($stream);
    }

    #[Test]
    public function confirmReturnsDefaultOnEmptyInput(): void
    {
        [$prompt, $output, $stream] = $this->createPrompt("\n");

        self::assertFalse($prompt->confirm('Continue?', false));
        self::assertStringContainsString('[y/N]', $output->buffer);
        fclose($stream);
    }

    #[Test]
    public function confirmReturnsDefaultTrueOnEmptyInput(): void
    {
        [$prompt, $output, $stream] = $this->createPrompt("\n");

        self::assertTrue($prompt->confirm('Continue?', true));
        self::assertStringContainsString('[Y/n]', $output->buffer);
        fclose($stream);
    }

    #[Test]
    public function confirmIsCaseInsensitive(): void
    {
        [$prompt, $output, $stream] = $this->createPrompt("Y\n");

        self::assertTrue($prompt->confirm('Continue?'));
        fclose($stream);
    }

    // --- select() ---

    #[Test]
    public function selectReturnsChosenOption(): void
    {
        [$prompt, $output, $stream] = $this->createPrompt("1\n");

        $result = $prompt->select('Pick color', ['Red', 'Green', 'Blue']);

        self::assertSame('Green', $result);
        fclose($stream);
    }

    #[Test]
    public function selectReturnsFirstOption(): void
    {
        [$prompt, $output, $stream] = $this->createPrompt("0\n");

        $result = $prompt->select('Pick', ['Apple', 'Banana']);

        self::assertSame('Apple', $result);
        fclose($stream);
    }

    #[Test]
    public function selectReturnsDefaultOnEmptyInput(): void
    {
        [$prompt, $output, $stream] = $this->createPrompt("\n");

        $result = $prompt->select('Pick', ['A', 'B', 'C'], 2);

        self::assertSame('C', $result);
        fclose($stream);
    }

    #[Test]
    public function selectDisplaysChoicesWithMarker(): void
    {
        [$prompt, $output, $stream] = $this->createPrompt("0\n");

        $prompt->select('Pick', ['First', 'Second'], 0);

        self::assertStringContainsString('> [0] First', $output->buffer);
        self::assertStringContainsString('  [1] Second', $output->buffer);
        fclose($stream);
    }

    #[Test]
    public function selectRepromptsOnInvalidInput(): void
    {
        // First input is invalid (out of range), second is valid
        [$prompt, $output, $stream] = $this->createPrompt("5\n0\n");

        $result = $prompt->select('Pick', ['A', 'B']);

        self::assertSame('A', $result);
        self::assertStringContainsString('Invalid selection', $output->errorBuffer);
        fclose($stream);
    }

    // --- multiselect() ---

    #[Test]
    public function multiselectReturnsMultipleChoices(): void
    {
        [$prompt, $output, $stream] = $this->createPrompt("0,2\n");

        $result = $prompt->multiselect('Pick colors', ['Red', 'Green', 'Blue']);

        self::assertSame(['Red', 'Blue'], $result);
        fclose($stream);
    }

    #[Test]
    public function multiselectReturnsSingleChoice(): void
    {
        [$prompt, $output, $stream] = $this->createPrompt("1\n");

        $result = $prompt->multiselect('Pick', ['A', 'B', 'C']);

        self::assertSame(['B'], $result);
        fclose($stream);
    }

    #[Test]
    public function multiselectReturnsDefaultsOnEmptyInput(): void
    {
        [$prompt, $output, $stream] = $this->createPrompt("\n");

        $result = $prompt->multiselect('Pick', ['A', 'B', 'C'], [0, 2]);

        self::assertSame(['A', 'C'], $result);
        fclose($stream);
    }

    #[Test]
    public function multiselectShowsCheckboxMarkers(): void
    {
        [$prompt, $output, $stream] = $this->createPrompt("0\n");

        $prompt->multiselect('Pick', ['A', 'B', 'C'], [1]);

        self::assertStringContainsString('[ ] 0. A', $output->buffer);
        self::assertStringContainsString('[x] 1. B', $output->buffer);
        self::assertStringContainsString('[ ] 2. C', $output->buffer);
        fclose($stream);
    }

    #[Test]
    public function multiselectRepromptsOnInvalidInput(): void
    {
        [$prompt, $output, $stream] = $this->createPrompt("9\n0\n");

        $result = $prompt->multiselect('Pick', ['A', 'B']);

        self::assertSame(['A'], $result);
        self::assertStringContainsString('Invalid selection', $output->errorBuffer);
        fclose($stream);
    }

    // --- password() ---

    #[Test]
    public function passwordReturnsInputOnNonTty(): void
    {
        // In test environment, this is not a TTY, so it falls back to visible input
        [$prompt, $output, $stream] = $this->createPrompt("secret123\n");

        $result = $prompt->password('Enter password');

        self::assertSame('secret123', $result);
        fclose($stream);
    }

    #[Test]
    public function passwordShowsFallbackMessage(): void
    {
        [$prompt, $output, $stream] = $this->createPrompt("pass\n");

        $prompt->password('Password');

        // On Windows or non-TTY, should show fallback message
        if (DIRECTORY_SEPARATOR === '\\' || !function_exists('posix_isatty')) {
            self::assertStringContainsString('input will be visible', $output->buffer);
        }
        fclose($stream);
    }

    // --- search() ---

    #[Test]
    public function searchFiltersAndPresentsResults(): void
    {
        // Type "app", then select result 0
        [$prompt, $output, $stream] = $this->createPrompt("app\n0\n");

        $result = $prompt->search(
            'Find framework',
            ['Laravel', 'Pulsar', 'Apple Framework', 'Application Kit'],
        );

        // Should find items containing "app"
        self::assertStringContainsString('app', strtolower($result));
        fclose($stream);
    }

    #[Test]
    public function searchReturnsEmptyOnNoMatch(): void
    {
        [$prompt, $output, $stream] = $this->createPrompt("zzzzzzz\n");

        $result = $prompt->search('Find', ['Alpha', 'Beta', 'Gamma']);

        self::assertSame('', $result);
        self::assertStringContainsString('No results', $output->buffer);
        fclose($stream);
    }

    #[Test]
    public function searchWithEmptyQueryShowsAllChoices(): void
    {
        [$prompt, $output, $stream] = $this->createPrompt("\n0\n");

        $result = $prompt->search('Find', ['Alpha', 'Beta']);

        self::assertSame('Alpha', $result);
        fclose($stream);
    }

    #[Test]
    public function searchIsCaseInsensitive(): void
    {
        [$prompt, $output, $stream] = $this->createPrompt("ALPHA\n0\n");

        $result = $prompt->search('Find', ['Alpha', 'Beta']);

        self::assertSame('Alpha', $result);
        fclose($stream);
    }

    // --- Edge cases ---

    #[Test]
    public function handlesEofGracefully(): void
    {
        [$prompt, $output, $stream] = $this->createPrompt('');

        // EOF should return empty string
        $result = $prompt->text('Name', 'fallback');

        self::assertSame('fallback', $result);
        fclose($stream);
    }
}
