<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\Compliance;

use NoDiscard;
use Pulsar\Api\Internal;

/**
 * Display names for the framework keys a compliance report uses.
 *
 * The keys are the report's own, so a framework that cannot appear in a report
 * cannot be named on a page either. That is the point: this is not a menu of
 * standards a site may advertise, it is the set of standards an assessment can
 * produce a finding for.
 */
#[Internal]
final readonly class FrameworkLabels
{
    /** @var array<string, non-empty-string> Report key => display name */
    private const array LABELS = [
        'soc2' => 'SOC 2',
        'hipaa' => 'HIPAA',
        'gdpr' => 'GDPR',
        'pci_dss' => 'PCI DSS',
        'nis2' => 'NIS2',
        'iso27001' => 'ISO 27001',
        'iso42001' => 'ISO 42001',
        'iso13485' => 'ISO 13485',
        'nist_csf' => 'NIST CSF',
        'ccpa' => 'CCPA',
        'dora' => 'DORA',
        'psd2' => 'PSD2',
        'eidas' => 'eIDAS',
        'hl7_fhir' => 'HL7 FHIR',
        'mdr' => 'MDR',
        'swift_csp' => 'SWIFT CSP',
        'dsa' => 'DSA',
        'data_act' => 'Data Act',
    ];

    /**
     * Keys used by the block before it was tied to the report.
     *
     * They are translated rather than dropped. Pages already carrying these
     * badges were making an unevidenced claim; the fix is for them to start
     * showing an observed result, not for them to render blank and leave a site
     * owner unaware that anything changed.
     *
     * @var array<string, string>
     */
    private const array LEGACY_ALIASES = [
        'pci-dss' => 'pci_dss',
        'iso-27001' => 'iso27001',
        'iso-42001' => 'iso42001',
        'iso-13485' => 'iso13485',
        'nist-csf' => 'nist_csf',
        'hl7-fhir' => 'hl7_fhir',
        'swift-csp' => 'swift_csp',
    ];

    /**
     * The report key for an author-supplied framework key.
     *
     * @return non-empty-string|null null when no report can emit this framework
     */
    #[NoDiscard]
    public static function canonical(string $key): ?string
    {
        $canonical = self::LEGACY_ALIASES[$key] ?? $key;

        return isset(self::LABELS[$canonical]) ? $canonical : null;
    }

    /**
     * @return non-empty-string|null null when the key is not one a report emits
     */
    #[NoDiscard]
    public static function for(string $key): ?string
    {
        $canonical = self::canonical($key);

        return $canonical === null ? null : self::LABELS[$canonical];
    }
}
