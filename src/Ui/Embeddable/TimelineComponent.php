<?php

declare(strict_types=1);

namespace Pulsar\Ui\Embeddable;

use Override;
use Pulsar\Api\Api;

use function htmlspecialchars;
use function sprintf;

use const ENT_QUOTES;

/**
 * Embeddable timeline component for activity/audit trail visualization.
 *
 * Renders a vertical timeline of events. Works as <pulsar-timeline> custom element.
 */
#[Api(since: '1.0.0')]
final class TimelineComponent extends EmbeddableComponent
{
    /** @var list<TimelineEntry> */
    private array $entries = [];

    #[Override]
    public function tagName(): string
    {
        return 'pulsar-timeline';
    }

    /**
     * @param list<TimelineEntry> $entries
     */
    public function entries(array $entries): self
    {
        $this->entries = $entries;

        return $this;
    }

    public function addEntry(TimelineEntry $entry): self
    {
        $this->entries[] = $entry;

        return $this;
    }

    #[Override]
    public function renderInner(): string
    {
        $html = '<ol class="pulsar-timeline" role="list">';

        foreach ($this->entries as $entry) {
            $title = htmlspecialchars($entry->title, ENT_QUOTES, 'UTF-8');
            $description = htmlspecialchars($entry->description, ENT_QUOTES, 'UTF-8');
            $timestamp = htmlspecialchars($entry->timestamp, ENT_QUOTES, 'UTF-8');

            $statusClass = $entry->status !== null
                ? ' pulsar-timeline-' . htmlspecialchars($entry->status, ENT_QUOTES, 'UTF-8')
                : '';

            $html .= sprintf(
                '<li class="pulsar-timeline-entry%s" role="listitem">'
                . '<time datetime="%s" class="pulsar-timeline-time">%s</time>'
                . '<div class="pulsar-timeline-content">'
                . '<h4 class="pulsar-timeline-title">%s</h4>'
                . '<p class="pulsar-timeline-desc">%s</p>'
                . '</div></li>',
                $statusClass,
                $timestamp,
                $timestamp,
                $title,
                $description,
            );
        }

        $html .= '</ol>';

        return $html;
    }
}
