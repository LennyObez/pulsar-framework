<?php

declare(strict_types=1);

namespace Pulsar\SupplyChain\Vex;

use Pulsar\Api\Api;

/**
 * A single vulnerability assessment within a VEX document.
 *
 * Each statement describes the exploitability status of one vulnerability
 * (identified by CVE ID) in the context of a specific product.
 */
#[Api(since: '1.0.0')]
final readonly class VexStatement
{
    /**
     * @param string $vulnerability CVE identifier (e.g. "CVE-2024-12345")
     * @param VexStatus $status Exploitability status
     * @param VexJustification|null $justification Required when status is NotAffected
     * @param string $actionStatement Human-readable remediation or impact note
     * @param string $product Affected product identifier (package name)
     */
    public function __construct(
        public string $vulnerability,
        public VexStatus $status,
        public ?VexJustification $justification = null,
        public string $actionStatement = '',
        public string $product = '',
    ) {}
}
