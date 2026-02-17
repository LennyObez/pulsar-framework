<?php

declare(strict_types=1);

namespace Pulsar\Ui\Embeddable;

use NoDiscard;
use Override;
use Pulsar\Api\Api;

use function array_keys;
use function htmlspecialchars;
use function sprintf;

use const ENT_QUOTES;

/**
 * Embeddable data table component.
 *
 * Renders a sortable, filterable, paginated table from any data source.
 * Works as <pulsar-data-table> custom element.
 */
#[Api(since: '1.0.0')]
final class DataTableComponent extends EmbeddableComponent
{
    /** @var list<array<string, scalar|null>> */
    private array $rows = [];

    /** @var list<ColumnDefinition> */
    private array $columns = [];

    private bool $sortable = true;
    private bool $filterable = false;
    private int $pageSize = 25;

    #[Override]
    public function tagName(): string
    {
        return 'pulsar-data-table';
    }

    /**
     * Set the table columns.
     *
     * @param list<ColumnDefinition> $columns
     */
    public function columns(array $columns): self
    {
        $this->columns = $columns;

        return $this;
    }

    /**
     * Set the table rows.
     *
     * @param list<array<string, scalar|null>> $rows
     */
    public function rows(array $rows): self
    {
        $this->rows = $rows;

        return $this;
    }

    public function sortable(bool $sortable = true): self
    {
        $this->sortable = $sortable;

        return $this;
    }

    public function filterable(bool $filterable = true): self
    {
        $this->filterable = $filterable;

        return $this;
    }

    public function pageSize(int $size): self
    {
        $this->pageSize = $size;

        return $this;
    }

    #[Override]
    #[NoDiscard]
    public function renderInner(): string
    {
        $columns = $this->columns;

        // Auto-detect columns from first row if not set
        if ($columns === [] && $this->rows !== []) {
            $keys = array_keys($this->rows[0]);
            foreach ($keys as $key) {
                $columns[] = new ColumnDefinition(
                    key: (string) $key,
                    label: ucfirst(str_replace('_', ' ', (string) $key)),
                );
            }
        }

        $html = '';

        if ($this->filterable) {
            $html .= '<div class="pulsar-dt-filter">';
            $html .= '<input type="search" placeholder="Filter..." aria-label="Filter table" />';
            $html .= '</div>';
        }

        $html .= '<table role="grid" aria-label="Data table">';
        $html .= '<thead><tr>';

        foreach ($columns as $col) {
            $sortAttr = $this->sortable ? ' aria-sort="none" role="columnheader"' : '';
            $label = htmlspecialchars($col->label, ENT_QUOTES, 'UTF-8');
            $html .= sprintf('<th%s>%s</th>', $sortAttr, $label);
        }

        $html .= '</tr></thead>';
        $html .= '<tbody>';

        foreach ($this->rows as $row) {
            $html .= '<tr>';
            foreach ($columns as $col) {
                $value = $row[$col->key] ?? '';
                $escaped = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
                $html .= sprintf('<td>%s</td>', $escaped);
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        $this->prop('pageSize', $this->pageSize);
        $this->prop('sortable', $this->sortable);

        return $html;
    }
}
