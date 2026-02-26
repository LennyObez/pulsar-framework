<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Address;
use Pulsar\Mail\Content;
use Pulsar\Mail\Envelope;
use Pulsar\Mail\Mailable;

#[CoversClass(Mailable::class)]
final class MailableTest extends TestCase
{
    private Address $defaultFrom;

    protected function setUp(): void
    {
        $this->defaultFrom = new Address('default@test.com', 'Default Sender');
    }

    #[Test]
    public function it_builds_message_from_envelope_and_content(): void
    {
        $mailable = new class extends Mailable {
            public function envelope(): Envelope
            {
                return new Envelope(
                    subject: 'Welcome',
                    to: [new Address('user@test.com', 'User')],
                    from: new Address('custom@test.com', 'Custom Sender'),
                );
            }

            public function content(): Content
            {
                return new Content(html: '<h1>Welcome</h1>', text: 'Welcome');
            }
        };

        $message = $mailable->build($this->defaultFrom);

        self::assertSame('Welcome', $message->subject);
        self::assertSame('custom@test.com', $message->from->email);
        self::assertCount(1, $message->to);
        self::assertSame('user@test.com', $message->to[0]->email);
        self::assertSame('<h1>Welcome</h1>', $message->htmlBody);
        self::assertSame('Welcome', $message->textBody);
    }

    #[Test]
    public function it_uses_default_from_when_envelope_has_no_from(): void
    {
        $mailable = new class extends Mailable {
            public function envelope(): Envelope
            {
                return new Envelope(subject: 'Test');
            }

            public function content(): Content
            {
                return new Content(text: 'Body');
            }
        };

        $message = $mailable->build($this->defaultFrom);

        self::assertSame('default@test.com', $message->from->email);
        self::assertSame('Default Sender', $message->from->name);
    }

    #[Test]
    public function it_overrides_to_with_fluent_api(): void
    {
        $mailable = new class extends Mailable {
            public function envelope(): Envelope
            {
                return new Envelope(
                    subject: 'Test',
                    to: [new Address('original@test.com')],
                );
            }

            public function content(): Content
            {
                return new Content(text: 'Body');
            }
        };

        $mailable->to('override@test.com', 'Override User');
        $message = $mailable->build($this->defaultFrom);

        self::assertCount(1, $message->to);
        self::assertSame('override@test.com', $message->to[0]->email);
        self::assertSame('Override User', $message->to[0]->name);
    }

    #[Test]
    public function it_overrides_subject_with_fluent_api(): void
    {
        $mailable = new class extends Mailable {
            public function envelope(): Envelope
            {
                return new Envelope(subject: 'Original');
            }

            public function content(): Content
            {
                return new Content(text: 'Body');
            }
        };

        $mailable->subject('Overridden Subject');
        $message = $mailable->build($this->defaultFrom);

        self::assertSame('Overridden Subject', $message->subject);
    }

    #[Test]
    public function it_overrides_from_with_fluent_api(): void
    {
        $mailable = new class extends Mailable {
            public function envelope(): Envelope
            {
                return new Envelope(subject: 'Test', from: new Address('envelope@test.com'));
            }

            public function content(): Content
            {
                return new Content(text: 'Body');
            }
        };

        $mailable->from('fluent@test.com', 'Fluent Sender');
        $message = $mailable->build($this->defaultFrom);

        self::assertSame('fluent@test.com', $message->from->email);
        self::assertSame('Fluent Sender', $message->from->name);
    }

    #[Test]
    public function it_adds_cc_and_bcc(): void
    {
        $mailable = new class extends Mailable {
            public function envelope(): Envelope
            {
                return new Envelope(subject: 'Test');
            }

            public function content(): Content
            {
                return new Content(text: 'Body');
            }
        };

        $mailable->cc('cc@test.com', 'CC User');
        $mailable->bcc(new Address('bcc@test.com', 'BCC User'));
        $message = $mailable->build($this->defaultFrom);

        self::assertCount(1, $message->cc);
        self::assertSame('cc@test.com', $message->cc[0]->email);
        self::assertCount(1, $message->bcc);
        self::assertSame('bcc@test.com', $message->bcc[0]->email);
    }

    #[Test]
    public function it_adds_reply_to(): void
    {
        $mailable = new class extends Mailable {
            public function envelope(): Envelope
            {
                return new Envelope(subject: 'Test');
            }

            public function content(): Content
            {
                return new Content(text: 'Body');
            }
        };

        $mailable->replyTo('reply@test.com');
        $message = $mailable->build($this->defaultFrom);

        self::assertNotNull($message->replyTo);
        self::assertSame('reply@test.com', $message->replyTo->email);
    }

    #[Test]
    public function it_adds_attachments(): void
    {
        $mailable = new class extends Mailable {
            public function envelope(): Envelope
            {
                return new Envelope(subject: 'Test');
            }

            public function content(): Content
            {
                return new Content(text: 'Body');
            }
        };

        $mailable->attach('report.pdf', 'PDF content', 'application/pdf');
        $message = $mailable->build($this->defaultFrom);

        self::assertCount(1, $message->attachments);
        self::assertSame('report.pdf', $message->attachments[0]->filename);
        self::assertSame('application/pdf', $message->attachments[0]->mimeType);
        self::assertFalse($message->attachments[0]->inline);
    }

    #[Test]
    public function it_adds_inline_attachments(): void
    {
        $mailable = new class extends Mailable {
            public function envelope(): Envelope
            {
                return new Envelope(subject: 'Test');
            }

            public function content(): Content
            {
                return new Content(html: '<img src="cid:logo">');
            }
        };

        $mailable->inline('logo', 'image data', 'image/png');
        $message = $mailable->build($this->defaultFrom);

        self::assertCount(1, $message->attachments);
        self::assertTrue($message->attachments[0]->inline);
        self::assertSame('logo', $message->attachments[0]->cid);
        self::assertSame('image/png', $message->attachments[0]->mimeType);
    }

    #[Test]
    public function it_sets_priority(): void
    {
        $mailable = new class extends Mailable {
            public function envelope(): Envelope
            {
                return new Envelope(subject: 'Test');
            }

            public function content(): Content
            {
                return new Content(text: 'Body');
            }
        };

        $mailable->priority(1);
        $message = $mailable->build($this->defaultFrom);

        self::assertSame(1, $message->priority);
    }

    #[Test]
    public function it_adds_custom_headers(): void
    {
        $mailable = new class extends Mailable {
            public function envelope(): Envelope
            {
                return new Envelope(subject: 'Test');
            }

            public function content(): Content
            {
                return new Content(text: 'Body');
            }
        };

        $mailable->header('X-Custom', 'value');
        $message = $mailable->build($this->defaultFrom);

        self::assertSame(['X-Custom' => 'value'], $message->headers);
    }

    #[Test]
    public function it_adds_metadata(): void
    {
        $mailable = new class extends Mailable {
            public function envelope(): Envelope
            {
                return new Envelope(subject: 'Test');
            }

            public function content(): Content
            {
                return new Content(text: 'Body');
            }
        };

        $mailable->metadata('campaign', 'launch');
        $message = $mailable->build($this->defaultFrom);

        self::assertSame(['campaign' => 'launch'], $message->metadata);
    }

    #[Test]
    public function it_sets_locale(): void
    {
        $mailable = new class extends Mailable {
            public function envelope(): Envelope
            {
                return new Envelope(subject: 'Test');
            }

            public function content(): Content
            {
                return new Content(text: 'Body');
            }
        };

        self::assertNull($mailable->getLocale());

        $mailable->locale('fr');
        self::assertSame('fr', $mailable->getLocale());
    }

    #[Test]
    public function it_returns_fluent_this(): void
    {
        $mailable = new class extends Mailable {
            public function envelope(): Envelope
            {
                return new Envelope(subject: 'Test');
            }

            public function content(): Content
            {
                return new Content(text: 'Body');
            }
        };

        $result = $mailable->to('a@test.com')
            ->cc('b@test.com')
            ->bcc('c@test.com')
            ->from('d@test.com')
            ->replyTo('e@test.com')
            ->subject('Chain')
            ->priority(2)
            ->header('X-Test', 'v')
            ->metadata('k', 'v')
            ->locale('de');

        self::assertSame($mailable, $result);
    }
}
