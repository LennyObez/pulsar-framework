<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function cal_days_in_month;
use function date;
use function htmlspecialchars;
use function is_array;
use function is_int;
use function is_string;

use const CAL_GREGORIAN;
use const ENT_QUOTES;

#[Internal]
final readonly class CalendarBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'calendar';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'year' => ['type' => 'integer', 'minimum' => 2000, 'maximum' => 2100],
                'month' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12],
                'posts' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'day' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 31],
                            'url' => ['type' => 'string', 'format' => 'uri'],
                            'count' => ['type' => 'integer', 'minimum' => 1],
                        ],
                        'required' => ['day', 'url'],
                    ],
                ],
                'prevMonthUrl' => ['type' => 'string', 'format' => 'uri'],
                'nextMonthUrl' => ['type' => 'string', 'format' => 'uri'],
            ],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var mixed $rawYear */
        $rawYear = $data['year'] ?? null;
        /** @var mixed $rawMonth */
        $rawMonth = $data['month'] ?? null;
        $year = is_int($rawYear) ? $rawYear : (int) date('Y');
        $month = is_int($rawMonth) ? $rawMonth : (int) date('n');

        if ($month < 1 || $month > 12) {
            $month = (int) date('n');
        }

        if ($year < 2000 || $year > 2100) {
            $year = (int) date('Y');
        }

        /** @var list<mixed> $posts */
        $posts = $data['posts'] ?? [];
        /** @var mixed $prevMonthUrl */
        $prevMonthUrl = $data['prevMonthUrl'] ?? null;
        /** @var mixed $nextMonthUrl */
        $nextMonthUrl = $data['nextMonthUrl'] ?? null;

        $postMap = [];

        foreach ($posts as $post) {
            if (!is_array($post)) {
                continue;
            }

            /** @var mixed $day */
            $day = $post['day'] ?? null;

            if (is_int($day) && $day >= 1 && $day <= 31) {
                /** @var mixed $rawUrl */
                $rawUrl = $post['url'] ?? null;
                $postMap[$day] = is_string($rawUrl) ? $rawUrl : '#';
            }
        }

        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        // mktime() can return false for invalid date components; one analyzer's
        // stub narrows the full-argument form to int, so state the real type.
        /** @var int|false $timestamp */
        $timestamp = mktime(0, 0, 0, $month, 1, $year);
        if ($timestamp === false) {
            $timestamp = time();
        }
        $firstDayOfWeek = (int) date('w', $timestamp);
        $monthName = date('F', $timestamp);
        $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

        $html = '<div class="calendar-block">';

        // Header with navigation
        $html .= '<div class="calendar-block__header">';

        if (is_string($prevMonthUrl)) {
            $prevUrl = htmlspecialchars($prevMonthUrl, ENT_QUOTES, 'UTF-8');
            $html .= "<a href=\"$prevUrl\" class=\"calendar-block__nav\" aria-label=\"Previous month\">&laquo;</a>";
        }

        $html .= "<span class=\"calendar-block__title\">$monthName $year</span>";

        if (is_string($nextMonthUrl)) {
            $nextUrl = htmlspecialchars($nextMonthUrl, ENT_QUOTES, 'UTF-8');
            $html .= "<a href=\"$nextUrl\" class=\"calendar-block__nav\" aria-label=\"Next month\">&raquo;</a>";
        }

        $html .= '</div>';

        // Calendar table
        $html .= '<table class="calendar-block__table" role="grid"><thead><tr>';

        foreach ($days as $day) {
            $html .= "<th scope=\"col\">$day</th>";
        }

        $html .= '</tr></thead><tbody><tr>';

        // Empty cells for days before first of month
        for ($i = 0; $i < $firstDayOfWeek; $i++) {
            $html .= '<td class="calendar-block__empty"></td>';
        }

        $currentDayOfWeek = $firstDayOfWeek;

        for ($day = 1; $day <= $daysInMonth; $day++) {
            if ($currentDayOfWeek === 7) {
                $html .= '</tr><tr>';
                $currentDayOfWeek = 0;
            }

            if (isset($postMap[$day])) {
                $url = htmlspecialchars($postMap[$day], ENT_QUOTES, 'UTF-8');
                $html .= "<td class=\"calendar-block__day calendar-block__day--has-posts\"><a href=\"$url\">$day</a></td>";
            } else {
                $html .= "<td class=\"calendar-block__day\">$day</td>";
            }

            $currentDayOfWeek++;
        }

        // Remaining empty cells
        while ($currentDayOfWeek < 7) {
            $html .= '<td class="calendar-block__empty"></td>';
            $currentDayOfWeek++;
        }

        return $html . '</tr></tbody></table></div>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (isset($data['year']) && (!is_int($data['year']) || $data['year'] < 2000 || $data['year'] > 2100)) {
            $errors[] = 'year must be an integer between 2000 and 2100';
        }

        if (isset($data['month']) && (!is_int($data['month']) || $data['month'] < 1 || $data['month'] > 12)) {
            $errors[] = 'month must be an integer between 1 and 12';
        }

        if (isset($data['posts']) && !is_array($data['posts'])) {
            $errors[] = 'posts must be an array';
        } elseif (isset($data['posts'])) {
            /** @var list<mixed> $posts */
            $posts = $data['posts'];

            foreach ($posts as $idx => $post) {
                $index = (string) $idx;

                if (!is_array($post)) {
                    $errors[] = "posts[{$index}] must be an object";

                    continue;
                }

                if (!isset($post['day']) || !is_int($post['day']) || $post['day'] < 1 || $post['day'] > 31) {
                    $errors[] = "posts[{$index}].day is required and must be an integer between 1 and 31";
                }

                if (!isset($post['url']) || !is_string($post['url'])) {
                    $errors[] = "posts[{$index}].url is required and must be a string";
                }
            }
        }

        if (isset($data['prevMonthUrl']) && !is_string($data['prevMonthUrl'])) {
            $errors[] = 'prevMonthUrl must be a string';
        }

        if (isset($data['nextMonthUrl']) && !is_string($data['nextMonthUrl'])) {
            $errors[] = 'nextMonthUrl must be a string';
        }

        return $errors;
    }
}
