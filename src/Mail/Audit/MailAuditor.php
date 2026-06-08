<?php

declare(strict_types=1);

namespace Pulsar\Mail\Audit;

use Pulsar\Api\Internal;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Mail\Attachment;
use Pulsar\Mail\Message;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Crypto\HmacInterface;
use Pulsar\Security\Crypto\MasterKey;
use SodiumException;

use function array_map;

/**
 * Creates audit records for mail operations.
 *
 * When HMAC hashing is enabled, computes integrity hashes of message body
 * and attachments using a derived subkey. Recipient addresses are always
 * pseudonymized via HMAC: raw email addresses never appear in audit logs.
 */
#[Internal]
final readonly class MailAuditor
{
    /**
     * Subkey ID for mail audit HMAC (matches MasterKey convention: 2 = audit HMAC).
     */
    private const int AUDIT_SUBKEY_ID = 2;

    /**
     * KDF context for mail audit subkey derivation (exactly 8 bytes).
     */
    private const string AUDIT_CONTEXT = 'mailaudt';

    public function __construct(
        private ?HmacInterface $hmac = null,
        private ?MasterKey $masterKey = null,
        private ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * @throws SodiumException
     */
    public function record(
        Message $message,
        string $messageId,
        DeliveryStatus $deliveryStatus,
        ?string $templateId = null,
        ?string $correlationId = null,
    ): MailAuditRecord {
        $auditKey = $this->deriveAuditKey();
        $recipientId = $this->pseudonymizeRecipient($message, $auditKey);
        $bodyHash = $this->computeBodyHash($message, $auditKey);
        $attachmentHashes = $this->computeAttachmentHashes($message, $auditKey);

        $record = new MailAuditRecord(
            messageId: $messageId,
            templateId: $templateId,
            recipientId: $recipientId,
            channel: 'email',
            sentAt: time(),
            deliveredAt: $deliveryStatus === DeliveryStatus::Delivered ? time() : null,
            deliveryStatus: $deliveryStatus,
            correlationId: $correlationId,
            bodyHash: $bodyHash,
            attachmentHashes: $attachmentHashes,
        );

        $this->auditLogger?->log(
            event: AuditEvent::Communication,
            outcome: $deliveryStatus === DeliveryStatus::Failed
                ? AuditOutcome::Failure
                : AuditOutcome::Success,
            actor: AuditActor::system('mail.auditor'),
            action: 'mail.send',
            resource: $messageId,
            metadata: $record->toMetadata(),
        );

        return $record;
    }

    /**
     * Derive the audit HMAC subkey from the master key.
     *
     * @throws SodiumException
     */
    private function deriveAuditKey(): ?string
    {
        return $this->masterKey?->deriveSubKey(
            self::AUDIT_SUBKEY_ID,
            self::AUDIT_CONTEXT,
        );
    }

    /**
     * Pseudonymize the first recipient email via HMAC.
     *
     * @throws SodiumException
     */
    private function pseudonymizeRecipient(Message $message, ?string $auditKey): string
    {
        $firstRecipient = $message->to[0]->email ?? 'unknown';

        if ($this->hmac === null || $auditKey === null) {
            return 'anon';
        }

        return $this->hmac->computeHex($firstRecipient, $auditKey);
    }

    /**
     * Compute HMAC of message body for tamper evidence.
     *
     * @throws SodiumException
     */
    private function computeBodyHash(Message $message, ?string $auditKey): ?string
    {
        if ($this->hmac === null || $auditKey === null) {
            return null;
        }

        $body = $message->htmlBody ?? $message->textBody ?? '';

        if ($body === '') {
            return null;
        }

        return $this->hmac->computeHex($body, $auditKey);
    }

    /**
     * Compute HMAC of each attachment's content.
     *
     * @return list<string>
     * @throws SodiumException
     */
    private function computeAttachmentHashes(Message $message, ?string $auditKey): array
    {
        $hmac = $this->hmac;
        if ($hmac === null || $auditKey === null || $message->attachments === []) {
            return [];
        }

        return array_map(
            static fn(Attachment $attachment): string => $hmac->computeHex($attachment->content, $auditKey),
            $message->attachments,
        );
    }
}
