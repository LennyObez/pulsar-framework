<?php

declare(strict_types=1);

namespace Pulsar\Console\Command;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Config\ConfigRepository;
use Pulsar\Config\Exception\ConfigException;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\Output\TableFormatter;
use Pulsar\Console\OutputInterface;
use ReflectionClass;
use ReflectionProperty;
use UnitEnum;

use function count;
use function get_debug_type;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function sprintf;
use function str_contains;

/**
 * Show resolved configuration values with types.
 *
 * Introspects config DTOs via reflection to display current values and types.
 * Accepts a list of known config class names and resolves each from the repository.
 *
 * Usage: debug:config [--filter=<pattern>]
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final class DebugConfigCommand extends Command
{
    /**
     * @param ConfigRepository $repository The loaded config repository
     * @param list<class-string> $configClasses Known config DTO class names to inspect
     */
    public function __construct(
        private readonly ConfigRepository $repository,
        private readonly array $configClasses,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'debug:config';
        $this->description = 'Show resolved configuration values with types';
        $this->addOption('filter', 'Filter config keys by pattern', 'f');
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $filter = $input->getNullableStringOption('filter');
        $loaded = 0;

        foreach ($this->configClasses as $className) {
            if (!$this->repository->has($className)) {
                continue;
            }

            try {
                $dto = $this->repository->get($className);
            } catch (ConfigException) {
                continue;
            }

            $this->renderConfigDto($output, $className, $dto, $filter);
            ++$loaded;
        }

        if ($loaded === 0) {
            $output->writeln('No configuration DTOs loaded.');
        } else {
            $output->success(sprintf('Displayed %d configuration DTO(s).', $loaded));
        }

        return ExitCode::Success->value;
    }

    private function renderConfigDto(OutputInterface $output, string $className, object $dto, ?string $filter): void
    {
        $reflection = new ReflectionClass($dto);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        if ($properties === []) {
            return;
        }

        $rows = [];

        foreach ($properties as $property) {
            $name = $property->getName();
            $qualifiedName = $className . '.' . $name;

            if ($filter !== null && !str_contains($qualifiedName, $filter) && !str_contains($name, $filter)) {
                continue;
            }

            /** @var mixed $value */
            $value = $property->getValue($dto);
            $rows[] = [
                $name,
                get_debug_type($value),
                $this->formatValue($value),
            ];
        }

        if ($rows === []) {
            return;
        }

        $shortName = $reflection->getShortName();
        $output->info(sprintf('%s (%s)', $shortName, $className));

        $table = new TableFormatter();
        $table->setHeaders(['Property', 'Type', 'Value']);

        foreach ($rows as $row) {
            $table->addRow($row);
        }

        $table->render($output);
        $output->newLine();
    }

    private function formatValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_string($value)) {
            if (mb_strlen($value) > 60) {
                return '"' . mb_substr($value, 0, 57) . '..."';
            }
            return '"' . $value . '"';
        }

        if (is_array($value)) {
            return sprintf('array(%d)', count($value));
        }

        if ($value instanceof UnitEnum) {
            return $value::class . '::' . $value->name;
        }

        if (is_object($value)) {
            return $value::class;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '(unknown)';
    }
}
