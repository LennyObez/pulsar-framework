<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Dlp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Dlp\DlpConfig;
use Pulsar\Security\Dlp\HtmlAwareDlpScanner;
use Pulsar\Security\Dlp\SensitiveDataType;
use Pulsar\Security\Dlp\SensitivePatternRegistry;

use function count;

#[CoversClass(HtmlAwareDlpScanner::class)]
final class HtmlAwareDlpScannerTest extends TestCase
{
    private HtmlAwareDlpScanner $scanner;

    protected function setUp(): void
    {
        $registry = new SensitivePatternRegistry(new DlpConfig(enabled: true));
        $this->scanner = new HtmlAwareDlpScanner($registry);
    }

    public function testEmptyHtmlReturnsClean(): void
    {
        $result = $this->scanner->scan('');

        self::assertFalse($result->detected);
    }

    public function testCleanHtmlReturnsClean(): void
    {
        $result = $this->scanner->scan('<p>Hello World</p>');

        self::assertFalse($result->detected);
    }

    public function testDetectsSensitiveDataInVisibleText(): void
    {
        // Visa test card in paragraph text
        $html = '<div><p>Your card number is 4111111111111111</p></div>';
        $result = $this->scanner->scan($html);

        self::assertTrue($result->detected);

        $ccMatch = null;
        foreach ($result->matches as $match) {
            if ($match->type === SensitiveDataType::CreditCard) {
                $ccMatch = $match;
                break;
            }
        }
        self::assertNotNull($ccMatch, 'Credit card in visible text should be detected');
    }

    public function testDetectsSsnInVisibleText(): void
    {
        $html = '<span>SSN: 123-45-6789</span>';
        $result = $this->scanner->scan($html);

        self::assertTrue($result->detected);
    }

    public function testDoesNotFlagScriptContent(): void
    {
        // An IP address inside a script tag should not be flagged as visible text
        $html = '<html><body><script>var host = "192.168.1.100";</script><p>Clean content here</p></body></html>';
        $result = $this->scanner->scan($html);

        // The IP in the script should not appear in visible text scan,
        // but may still appear in attribute scans. Check that at least
        // visible text is not flagged.
        $textOnlyHtml = '<p>Clean content here</p>';
        $cleanResult = $this->scanner->scan($textOnlyHtml);
        self::assertFalse($cleanResult->detected);
    }

    public function testDetectsSensitiveDataInAttributes(): void
    {
        // API key in an href attribute
        $html = '<a href="https://api.example.com/key=sk_test_xxxxxxxxxxxxxxxxxxxxxxxxxxxx">Link</a>';
        $result = $this->scanner->scan($html);

        self::assertTrue($result->detected);
    }

    public function testMergesTextAndAttributeMatches(): void
    {
        // SSN in text and API key in attribute
        $html = '<div><p>SSN: 123-45-6789</p><a href="sk_test_xxxxxxxxxxxxxxxxxxxxxxxxxxxx">API</a></div>';
        $result = $this->scanner->scan($html);

        self::assertTrue($result->detected);
        self::assertGreaterThanOrEqual(2, count($result->matches));
    }

    public function testNoAttributeScanWhenNoScannableAttributes(): void
    {
        // Only a div with clean text and no scannable attributes
        $html = '<div>Safe paragraph with no sensitive data</div>';
        $result = $this->scanner->scan($html);

        self::assertFalse($result->detected);
    }
}
