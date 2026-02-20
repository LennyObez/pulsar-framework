<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Security\PhiScrubber;

#[CoversClass(PhiScrubber::class)]
final class PhiScrubberTest extends TestCase
{
    private PhiScrubber $scrubber;

    protected function setUp(): void
    {
        $this->scrubber = new PhiScrubber();
    }

    #[Test]
    public function it_detects_ssn_with_dashes(): void
    {
        self::assertTrue($this->scrubber->containsPhi('SSN is 123-45-6789'));
    }

    #[Test]
    public function it_scrubs_ssn_with_dashes(): void
    {
        $result = $this->scrubber->scrub('subject', 'SSN is 123-45-6789');

        self::assertStringContainsString('[REDACTED]', $result);
        self::assertStringNotContainsString('123-45-6789', $result);
    }

    #[Test]
    public function it_detects_ssn_without_dashes(): void
    {
        self::assertTrue($this->scrubber->containsPhi('SSN 123456789'));
    }

    #[Test]
    public function it_detects_mrn(): void
    {
        self::assertTrue($this->scrubber->containsPhi('MRN#12345678'));
        self::assertTrue($this->scrubber->containsPhi('MRN: 1234567'));
        self::assertTrue($this->scrubber->containsPhi('MRN-9876'));
    }

    #[Test]
    public function it_scrubs_mrn(): void
    {
        $result = $this->scrubber->scrub('body', 'Patient MRN#12345678 info');

        self::assertStringContainsString('[REDACTED]', $result);
        self::assertStringNotContainsString('MRN#12345678', $result);
    }

    #[Test]
    public function it_detects_phone_numbers(): void
    {
        self::assertTrue($this->scrubber->containsPhi('Call (555) 123-4567'));
        self::assertTrue($this->scrubber->containsPhi('Phone: 555-123-4567'));
        self::assertTrue($this->scrubber->containsPhi('Mobile: 5551234567'));
    }

    #[Test]
    public function it_scrubs_phone_numbers(): void
    {
        $result = $this->scrubber->scrub('field', 'Call (555) 123-4567 now');

        self::assertStringContainsString('[REDACTED]', $result);
        self::assertStringNotContainsString('555', $result);
    }

    #[Test]
    public function it_detects_email_in_subjects(): void
    {
        self::assertTrue($this->scrubber->containsPhi('Contact patient@hospital.org for details'));
    }

    #[Test]
    public function it_scrubs_email_addresses(): void
    {
        $result = $this->scrubber->scrub('subject', 'Contact patient@hospital.org');

        self::assertStringContainsString('[REDACTED]', $result);
        self::assertStringNotContainsString('patient@hospital.org', $result);
    }

    #[Test]
    public function it_does_not_detect_clean_text(): void
    {
        self::assertFalse($this->scrubber->containsPhi('Hello World'));
        self::assertFalse($this->scrubber->containsPhi('Order status update'));
    }

    #[Test]
    public function it_returns_clean_text_unchanged(): void
    {
        $input = 'This is a normal message with no PHI';
        $result = $this->scrubber->scrub('field', $input);

        self::assertSame($input, $result);
    }

    #[Test]
    public function it_scrubs_multiple_patterns_in_one_value(): void
    {
        $input = 'Patient SSN 123-45-6789, phone (555) 123-4567, email test@example.com';
        $result = $this->scrubber->scrub('body', $input);

        self::assertStringNotContainsString('123-45-6789', $result);
        self::assertStringNotContainsString('test@example.com', $result);
    }

    #[Test]
    public function it_accepts_custom_patterns(): void
    {
        $customScrubber = new PhiScrubber(['/\bCUSTOM-\d{4}\b/']);

        self::assertTrue($customScrubber->containsPhi('Ref: CUSTOM-1234'));
        self::assertFalse($customScrubber->containsPhi('Normal text'));

        $result = $customScrubber->scrub('field', 'Ref: CUSTOM-1234');
        self::assertStringContainsString('[REDACTED]', $result);
    }

    #[Test]
    public function it_detects_dob_with_label(): void
    {
        self::assertTrue($this->scrubber->containsPhi('DOB: 01/15/1990'));
        self::assertTrue($this->scrubber->containsPhi('Date of Birth: 12-25-1985'));
    }

    #[Test]
    public function it_detects_date_patterns(): void
    {
        self::assertTrue($this->scrubber->containsPhi('Appointment on 12/25/2024'));
    }
}
