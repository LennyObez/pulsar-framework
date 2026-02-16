<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Forms;

use Override;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Cms\Forms\Event\FormSubmitted;
use Pulsar\Extension\Cms\Forms\FormSubmission;
use Pulsar\Extension\Cms\Forms\FormSubmissionRepositoryInterface;
use Pulsar\Extension\Cms\Forms\FormSubmissionServiceInterface;
use Pulsar\Extension\Cms\Forms\SpamDetection\SpamScorer;
use Pulsar\Mail\MailManager;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Security\Csrf\CsrfTokenManagerInterface;
use RuntimeException;
use Throwable;

use function array_keys;
use function bin2hex;
use function implode;
use function is_scalar;
use function is_string;
use function sodium_crypto_generichash;
use function str_contains;
use function str_replace;

/**
 * Processes form submissions with CSRF validation, spam detection,
 * evidence hashing, email notification, and metric emission.
 */
#[Internal(reason: 'Form submission service; use FormSubmissionServiceInterface for public API')]
final readonly class FormSubmissionService implements FormSubmissionServiceInterface
{
    /**
     * @param list<string> $notificationRecipients
     */
    public function __construct(
        private FormSubmissionRepositoryInterface $repository,
        private SpamScorer $spamScorer,
        private MailManager $mailManager,
        private EventDispatcherInterface $eventDispatcher,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private LoggerInterface $logger,
        private array $notificationRecipients = [],
        private ?MetricRegistry $metricRegistry = null,
    ) {}

    #[Override]
    public function submit(array $formData, array $meta): FormSubmission
    {
        // Validate CSRF token
        $csrfToken = is_string($meta['_csrf_token'] ?? null) ? $meta['_csrf_token'] : '';

        if (!$this->csrfTokenManager->validate($csrfToken)) {
            throw new RuntimeException('Invalid or missing CSRF token');
        }

        // Hash IP and user agent for privacy-preserving identification
        $ip = is_string($meta['ip'] ?? null) ? $meta['ip'] : '0.0.0.0';
        $userAgent = is_string($meta['user_agent'] ?? null) ? $meta['user_agent'] : '';

        $ipHash = bin2hex(sodium_crypto_generichash($ip));
        $userAgentHash = bin2hex(sodium_crypto_generichash($userAgent));

        // Run spam detection
        $spamResult = $this->spamScorer->score($formData, $meta);

        // Extract form block and content identifiers
        $formBlockId = is_string($meta['form_block_id'] ?? null) ? $meta['form_block_id'] : '';
        $contentId = is_string($meta['content_id'] ?? null) ? $meta['content_id'] : '';
        $tenantId = is_string($meta['tenant_id'] ?? null) ? $meta['tenant_id'] : null;

        $submission = FormSubmission::create(
            formBlockId: $formBlockId,
            contentId: $contentId,
            data: $formData,
            ipHash: $ipHash,
            userAgentHash: $userAgentHash,
            spamScore: $spamResult->score,
            spamReason: $spamResult->reason,
            isSpam: $spamResult->isSpam,
            tenantId: $tenantId,
        );

        $this->repository->save($submission);

        // Send email notification for non-spam submissions
        if (!$submission->isSpam && $this->notificationRecipients !== []) {
            $mailable = new FormNotificationMailable($submission, $this->notificationRecipients);

            try {
                $this->mailManager->send($mailable);
            } catch (Throwable $e) {
                $this->logger->error('Failed to send form notification email', [
                    'submission_id' => $submission->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Dispatch event
        $this->eventDispatcher->dispatch(new FormSubmitted($submission));

        // Emit metric
        if ($this->metricRegistry !== null) {
            $labels = new LabelSet(['spam' => $submission->isSpam ? 'true' : 'false']);

            if ($this->metricRegistry->has('cms.form.submissions')) {
                $this->metricRegistry->counter('cms.form.submissions')->increment($labels);
            }
        }

        $this->logger->info('Form submission processed', [
            'submission_id' => $submission->id,
            'content_id' => $submission->contentId,
            'is_spam' => $submission->isSpam,
            'spam_score' => $submission->spamScore,
        ]);

        return $submission;
    }

    #[Override]
    public function markAsRead(string $id, ?string $tenantId = null): void
    {
        $submission = $this->repository->findById($id, $tenantId);

        if ($submission === null) {
            throw new RuntimeException('Form submission not found: ' . $id);
        }

        $this->repository->markAsRead($id);
    }

    #[Override]
    public function markAsSpam(string $id, string $reason, ?string $tenantId = null): void
    {
        $submission = $this->repository->findById($id, $tenantId);

        if ($submission === null) {
            throw new RuntimeException('Form submission not found: ' . $id);
        }

        $this->repository->markAsSpam($id, $reason);
    }

    #[Override]
    public function exportSubmissions(string $contentId, ?string $tenantId = null): string
    {
        $submissions = $this->repository->findByContentId($contentId, $tenantId);

        if ($submissions === []) {
            return '';
        }

        // Collect all unique field keys across submissions
        $allKeys = [];

        foreach ($submissions as $submission) {
            foreach (array_keys($submission->data) as $key) {
                $allKeys[$key] = true;
            }
        }

        $headers = ['id', 'submitted_at', 'is_spam', 'spam_score', ...array_keys($allKeys)];

        $lines = [];
        $lines[] = self::csvLine($headers);

        foreach ($submissions as $submission) {
            $row = [
                $submission->id,
                $submission->submittedAt->format('c'),
                $submission->isSpam ? 'yes' : 'no',
                (string) $submission->spamScore,
            ];

            foreach (array_keys($allKeys) as $key) {
                $value = $submission->data[$key] ?? '';
                $row[] = is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
            }

            $lines[] = self::csvLine($row);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<string> $values
     */
    private static function csvLine(array $values): string
    {
        $escaped = [];

        foreach ($values as $value) {
            $value = self::sanitizeCsvValue($value);

            if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
                $escaped[] = '"' . str_replace('"', '""', $value) . '"';
            } else {
                $escaped[] = $value;
            }
        }

        return implode(',', $escaped);
    }

    /**
     * Prevent CSV injection by prefixing dangerous characters with a single quote.
     *
     * Spreadsheet applications interpret cells starting with =, +, -, @, \t, or \r
     * as formulas, which can be exploited for data exfiltration or code execution.
     */
    private static function sanitizeCsvValue(string $value): string
    {
        if ($value !== '' && str_contains("=+-@\t\r", $value[0])) {
            return "'" . $value;
        }

        return $value;
    }
}
