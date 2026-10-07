<?php

declare(strict_types=1);

namespace Pulsar\Extension\Form\Contract;

use Pulsar\Api\Api;

/**
 * Port for antivirus scanning of uploaded files.
 *
 * Implementations connect to ClamAV, cloud-based scanning services,
 * or any other virus scanning infrastructure.
 * @api
 */
#[Api(since: '1.0.0')]
interface AntivirusPort
{
    /**
     * Scan a file for malware.
     *
     * @param string $filePath Absolute path to the file to scan
     *
     * @return AntivirusScanResult The scan result with clean/infected status
     */
    public function scan(string $filePath): AntivirusScanResult;
}
