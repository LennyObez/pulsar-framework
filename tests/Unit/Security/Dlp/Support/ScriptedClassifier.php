<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Dlp\Support;

use Override;
use Pulsar\Security\Dlp\DlpScanResult;
use Pulsar\Security\Dlp\SensitiveDataClassifierInterface;

/** A classifier that answers one fixed result and records what it was asked to read. */
final class ScriptedClassifier implements SensitiveDataClassifierInterface
{
    /** @var list<string> */
    public private(set) array $scanned = [];

    public function __construct(private readonly DlpScanResult $result) {}

    #[Override]
    public function scan(string $content): DlpScanResult
    {
        $this->scanned[] = $content;

        return $this->result;
    }
}
