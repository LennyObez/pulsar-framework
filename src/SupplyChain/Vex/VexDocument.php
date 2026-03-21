<?php

declare(strict_types=1);

namespace Pulsar\SupplyChain\Vex;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * A complete VEX (Vulnerability Exploitability eXchange) document.
 *
 * Contains one or more statements describing the exploitability of
 * known vulnerabilities in the project's dependency tree.
 *
 * Conforms to the OpenVEX specification.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class VexDocument
{
    /**
     * @param string $documentId Unique document identifier (URN)
     * @param int $version Document version (incremented on updates)
     * @param DateTimeImmutable $timestamp When the document was generated
     * @param string $tooling Name and version of the tool that generated this document
     * @param list<VexStatement> $statements Vulnerability assessment statements
     */
    public function __construct(
        public string $documentId,
        public int $version,
        public DateTimeImmutable $timestamp,
        public string $tooling,
        public array $statements,
    ) {}
}
