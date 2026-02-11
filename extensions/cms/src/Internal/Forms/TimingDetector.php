<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Forms;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Forms\SpamDetection\SpamDetectorInterface;
use Pulsar\Extension\Cms\Forms\SpamDetection\SpamResult;

use function is_numeric;
use function time;

/**
 * Detects spam by checking the time between form render and submission.
 *
 * Human users need at least a few seconds to fill a form.
 * Bots typically submit instantly.
 */
#[Internal(reason: 'Spam detector — use SpamDetectorInterface')]
final readonly class TimingDetector implements SpamDetectorInterface
{
    private const int MIN_SECONDS = 3;

    #[Override]
    public function detect(array $data, array $meta): SpamResult
    {
        // Only read from $meta — the controller extracts _form_rendered_at before
        // stripping _-prefixed keys from $data, so $data never contains this key.
        $renderedAt = $meta['_form_rendered_at'] ?? null;

        if (!is_numeric($renderedAt)) {
            return new SpamResult(false, 0.0, null);
        }

        $elapsed = time() - (int) $renderedAt;

        if ($elapsed < self::MIN_SECONDS) {
            return new SpamResult(true, 8.0, 'Form submitted too quickly (' . $elapsed . 's)');
        }

        return new SpamResult(false, 0.0, null);
    }
}
