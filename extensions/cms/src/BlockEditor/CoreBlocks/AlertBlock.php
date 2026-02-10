<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function htmlspecialchars;
use function in_array;
use function is_string;

use const ENT_QUOTES;

#[Internal]
final readonly class AlertBlock implements BlockTypeInterface
{
    private const array VALID_ALERT_TYPES = ['info', 'warning', 'error', 'success'];

    #[Override]
    public function type(): string
    {
        return 'alert';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'message' => ['type' => 'string'],
                'alertType' => ['type' => 'string', 'enum' => self::VALID_ALERT_TYPES],
                'dismissible' => ['type' => 'boolean'],
            ],
            'required' => ['message', 'alertType'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        $message = htmlspecialchars((string) ($data['message'] ?? ''), ENT_QUOTES, 'UTF-8');
        $alertType = 'info';

        if (isset($data['alertType']) && is_string($data['alertType']) && in_array($data['alertType'], self::VALID_ALERT_TYPES, true)) {
            $alertType = $data['alertType'];
        }

        $dismissAttr = '';

        if (!empty($data['dismissible'])) {
            $dismissAttr = ' data-dismissible="true"';
        }

        return "<div class=\"alert alert--{$alertType}\" role=\"alert\"{$dismissAttr}>{$message}</div>";
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['message']) || !is_string($data['message'])) {
            $errors[] = 'message is required and must be a string';
        }

        if (!isset($data['alertType']) || !is_string($data['alertType']) || !in_array($data['alertType'], self::VALID_ALERT_TYPES, true)) {
            $errors[] = 'alertType is required and must be one of: info, warning, error, success';
        }

        return $errors;
    }
}
