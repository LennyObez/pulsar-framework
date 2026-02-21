<?php

declare(strict_types=1);

namespace Pulsar\Console\Output;

use Pulsar\Api\Api;
use Pulsar\Console\OutputInterface;

/**
 * Formats tabular data for console output.
 */
#[Api(since: '1.0.0')]
final class TableFormatter
{
    /** @var list<string> */
    private array $headers = [];

    /** @var list<list<string>> */
    private array $rows = [];

    /**
     * Set the table headers.
     *
     * @param list<string> $headers
     */
    public function setHeaders(array $headers): self
    {
        $this->headers = $headers;
        return $this;
    }

    /**
     * Add a row to the table.
     *
     * @param list<string> $row
     */
    public function addRow(array $row): self
    {
        $this->rows[] = $row;
        return $this;
    }

    /**
     * Set all rows at once.
     *
     * @param list<list<string>> $rows
     */
    public function setRows(array $rows): self
    {
        $this->rows = $rows;
        return $this;
    }

    /**
     * Render the table to the output.
     */
    public function render(OutputInterface $output): void
    {
        if ($this->headers === [] && $this->rows === []) {
            return;
        }

        $widths = $this->calculateColumnWidths();

        // Top border
        $output->writeln($this->renderBorder($widths, '┌', '┬', '┐'));

        // Headers
        if ($this->headers !== []) {
            $output->writeln($this->renderRow($this->headers, $widths));
            $output->writeln($this->renderBorder($widths, '├', '┼', '┤'));
        }

        // Data rows
        foreach ($this->rows as $row) {
            $output->writeln($this->renderRow($row, $widths));
        }

        // Bottom border
        $output->writeln($this->renderBorder($widths, '└', '┴', '┘'));
    }

    /**
     * Calculate the width of each column.
     *
     * @return list<int>
     */
    private function calculateColumnWidths(): array
    {
        // Account for headers
        $widths = array_map(mb_strlen(...), $this->headers);

        // Account for data rows
        foreach ($this->rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen($cell));
            }
        }

        // Ensure minimum width of 3
        return array_values(array_map(fn(int $w) => max($w, 3), $widths));
    }

    /**
     * Render a horizontal border.
     *
     * @param list<int> $widths
     */
    private function renderBorder(array $widths, string $left, string $middle, string $right): string
    {
        $parts = [];
        foreach ($widths as $width) {
            $parts[] = str_repeat('─', $width + 2);
        }

        return $left . implode($middle, $parts) . $right;
    }

    /**
     * Render a data row.
     *
     * @param list<string> $row
     * @param list<int> $widths
     */
    private function renderRow(array $row, array $widths): string
    {
        $cells = [];
        foreach ($widths as $i => $width) {
            $cell = $row[$i] ?? '';
            $cells[] = ' ' . str_pad($cell, $width) . ' ';
        }

        return '│' . implode('│', $cells) . '│';
    }

    /**
     * Reset the table.
     */
    public function reset(): self
    {
        $this->headers = [];
        $this->rows = [];
        return $this;
    }
}
