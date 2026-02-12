<?php

declare(strict_types=1);

namespace Pulsar\Mail;

use Pulsar\Api\Api;

/**
 * An immutable email message ready for transport.
 */
#[Api(since: '1.0.0')]
final readonly class Message
{
    /**
     * @param list<Address>        $to
     * @param list<Address>        $cc
     * @param list<Address>        $bcc
     * @param list<Attachment>     $attachments
     * @param array<string,string> $headers
     * @param array<string,mixed>  $metadata
     */
    public function __construct(
        public Address $from,
        public array $to,
        public string $subject,
        public array $cc = [],
        public array $bcc = [],
        public ?Address $replyTo = null,
        public ?string $htmlBody = null,
        public ?string $textBody = null,
        public array $attachments = [],
        public array $headers = [],
        public int $priority = 3,
        public array $metadata = [],
    ) {}
}
