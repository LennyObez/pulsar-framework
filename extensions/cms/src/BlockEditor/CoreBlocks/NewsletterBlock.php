<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\CoreBlocks;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;

use function htmlspecialchars;
use function is_string;

use const ENT_QUOTES;

/**
 * Newsletter signup block for the block editor.
 *
 * Renders a web component `<cms-newsletter-signup>` that handles
 * the subscription form with client-side validation and AJAX submission.
 */
#[Internal]
final readonly class NewsletterBlock implements BlockTypeInterface
{
    #[Override]
    public function type(): string
    {
        return 'newsletter';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'heading' => ['type' => 'string', 'description' => 'Form heading text'],
                'description' => ['type' => 'string', 'description' => 'Optional description below the heading'],
                'buttonText' => ['type' => 'string', 'description' => 'Subscribe button label'],
                'successMessage' => ['type' => 'string', 'description' => 'Message shown after successful subscription'],
            ],
            'required' => ['heading', 'buttonText'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        /** @var string $rawHeading */
        $rawHeading = $data['heading'] ?? 'Subscribe to our newsletter';
        $heading = htmlspecialchars($rawHeading, ENT_QUOTES, 'UTF-8');

        /** @var string $rawDescription */
        $rawDescription = $data['description'] ?? '';
        $description = htmlspecialchars($rawDescription, ENT_QUOTES, 'UTF-8');

        /** @var string $rawButton */
        $rawButton = $data['buttonText'] ?? 'Subscribe';
        $buttonText = htmlspecialchars($rawButton, ENT_QUOTES, 'UTF-8');

        /** @var string $rawSuccess */
        $rawSuccess = $data['successMessage'] ?? 'Thank you for subscribing! Please check your email to confirm.';
        $successMessage = htmlspecialchars($rawSuccess, ENT_QUOTES, 'UTF-8');

        $descriptionAttr = $description !== ''
            ? " data-description=\"{$description}\""
            : '';

        return '<cms-newsletter-signup'
            . " data-heading=\"{$heading}\""
            . $descriptionAttr
            . " data-button-text=\"{$buttonText}\""
            . " data-success-message=\"{$successMessage}\""
            . '></cms-newsletter-signup>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['heading']) || !is_string($data['heading']) || $data['heading'] === '') {
            $errors[] = 'heading is required and must be a non-empty string';
        }

        if (!isset($data['buttonText']) || !is_string($data['buttonText']) || $data['buttonText'] === '') {
            $errors[] = 'buttonText is required and must be a non-empty string';
        }

        if (isset($data['description']) && !is_string($data['description'])) {
            $errors[] = 'description must be a string';
        }

        if (isset($data['successMessage']) && !is_string($data['successMessage'])) {
            $errors[] = 'successMessage must be a string';
        }

        return $errors;
    }
}
