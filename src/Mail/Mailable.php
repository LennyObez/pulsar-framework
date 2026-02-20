<?php

declare(strict_types=1);

namespace Pulsar\Mail;

use Pulsar\Api\Api;

/**
 * Base class for typed mailables — define envelope + content, optionally override with fluent API.
 */
#[Api(since: '1.0.0')]
abstract class Mailable
{
    /** @var list<Address> */
    protected array $toOverrides = [];

    /** @var list<Address> */
    protected array $ccOverrides = [];

    /** @var list<Address> */
    protected array $bccOverrides = [];

    protected ?Address $fromOverride = null;

    protected ?Address $replyToOverride = null;

    protected ?string $subjectOverride = null;

    /** @var list<Attachment> */
    protected array $attachments = [];

    protected int $priorityValue = 3;

    /** @var array<string, string> */
    protected array $headerOverrides = [];

    /** @var array<string, mixed> */
    protected array $metadataValues = [];

    protected ?string $localeValue = null;

    /**
     * Define the message envelope (subject, from, recipients).
     */
    abstract public function envelope(): Envelope;

    /**
     * Define the message content (HTML, plain text).
     */
    abstract public function content(): Content;

    /**
     * Add a "to" recipient.
     *
     * @return $this
     */
    public function to(string|Address $address, ?string $name = null): static
    {
        $this->toOverrides[] = $address instanceof Address
            ? $address
            : new Address($address, $name ?? '');

        return $this;
    }

    /**
     * Add a "cc" recipient.
     *
     * @return $this
     */
    public function cc(string|Address $address, ?string $name = null): static
    {
        $this->ccOverrides[] = $address instanceof Address
            ? $address
            : new Address($address, $name ?? '');

        return $this;
    }

    /**
     * Add a "bcc" recipient.
     *
     * @return $this
     */
    public function bcc(string|Address $address, ?string $name = null): static
    {
        $this->bccOverrides[] = $address instanceof Address
            ? $address
            : new Address($address, $name ?? '');

        return $this;
    }

    /**
     * Override the sender address.
     *
     * @return $this
     */
    public function from(string|Address $address, ?string $name = null): static
    {
        $this->fromOverride = $address instanceof Address
            ? $address
            : new Address($address, $name ?? '');

        return $this;
    }

    /**
     * Override the reply-to address.
     *
     * @return $this
     */
    public function replyTo(string|Address $address, ?string $name = null): static
    {
        $this->replyToOverride = $address instanceof Address
            ? $address
            : new Address($address, $name ?? '');

        return $this;
    }

    /**
     * Override the subject line.
     *
     * @return $this
     */
    public function subject(string $subject): static
    {
        $this->subjectOverride = $subject;

        return $this;
    }

    /**
     * Attach a file by providing its content.
     *
     * @return $this
     */
    public function attach(string $filename, string $content, ?string $mimeType = null): static
    {
        $this->attachments[] = new Attachment(
            filename: $filename,
            content: $content,
            mimeType: $mimeType ?? 'application/octet-stream',
        );

        return $this;
    }

    /**
     * Attach an inline image (for HTML embedding via CID).
     *
     * @return $this
     */
    public function inline(string $cid, string $content, string $mimeType): static
    {
        $this->attachments[] = new Attachment(
            filename: $cid,
            content: $content,
            mimeType: $mimeType,
            inline: true,
            cid: $cid,
        );

        return $this;
    }

    /**
     * Set message priority (1 = highest, 5 = lowest).
     *
     * @return $this
     */
    public function priority(int $priority): static
    {
        $this->priorityValue = $priority;

        return $this;
    }

    /**
     * Add a custom header.
     *
     * @return $this
     */
    public function header(string $key, string $value): static
    {
        $this->headerOverrides[$key] = $value;

        return $this;
    }

    /**
     * Add metadata to the message.
     *
     * @return $this
     */
    public function metadata(string $key, mixed $value): static
    {
        $this->metadataValues[$key] = $value;

        return $this;
    }

    /**
     * Set the locale for this mailable.
     *
     * @return $this
     */
    public function locale(string $locale): static
    {
        $this->localeValue = $locale;

        return $this;
    }

    /**
     * Get the configured locale, if any.
     */
    public function getLocale(): ?string
    {
        return $this->localeValue;
    }

    /**
     * Build the final Message from envelope + content + fluent overrides.
     */
    public function build(Address $defaultFrom): Message
    {
        $envelope = $this->envelope();
        $content = $this->content();

        $to = $this->toOverrides !== [] ? $this->toOverrides : $envelope->to;
        $cc = $this->ccOverrides !== [] ? $this->ccOverrides : $envelope->cc;
        $bcc = $this->bccOverrides !== [] ? $this->bccOverrides : $envelope->bcc;
        $from = $this->fromOverride ?? $envelope->from ?? $defaultFrom;
        $replyTo = $this->replyToOverride ?? $envelope->replyTo;
        $subject = $this->subjectOverride ?? $envelope->subject;

        return new Message(
            from: $from,
            to: $to,
            subject: $subject,
            cc: $cc,
            bcc: $bcc,
            replyTo: $replyTo,
            htmlBody: $content->html,
            textBody: $content->text,
            attachments: $this->attachments,
            headers: $this->headerOverrides,
            priority: $this->priorityValue,
            metadata: $this->metadataValues,
        );
    }
}
