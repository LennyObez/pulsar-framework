<?php

declare(strict_types=1);

namespace Pulsar\Mail;

use Pulsar\Api\Api;

/**
 * Defines the envelope (sender, recipients, subject) for a mailable.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Envelope
{
    /**
     * @param list<Address> $to
     * @param list<Address> $cc
     * @param list<Address> $bcc
     */
    public function __construct(
        public string $subject,
        public ?Address $from = null,
        public array $to = [],
        public array $cc = [],
        public array $bcc = [],
        public ?Address $replyTo = null,
    ) {}
}
