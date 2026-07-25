<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Internal\Certificate;

use Pulsar\Api\Internal;
use Pulsar\Extension\Psd2\Internal\Certificate\Asn1\DerDecoder;
use Pulsar\Extension\Psd2\Internal\Certificate\Asn1\DerNode;
use Throwable;

use function base64_decode;
use function is_string;
use function preg_replace;
use function sha1;
use function substr;

/**
 * Extracts the X.509 fields the OCSP/CRL revocation checks need, by decoding the
 * certificate's DER rather than trusting openssl_x509_parse's string rendering.
 *
 * All accessors are computed from the tbsCertificate structure:
 *   tbsCertificate ::= SEQUENCE { [0] version?, serialNumber, signature,
 *       issuer Name, validity, subject Name, subjectPublicKeyInfo, …, [3] extensions }
 */
#[Internal(reason: 'X.509 field extraction for revocation checks')]
final readonly class CertificateFields
{
    private const string OID_AIA = '1.3.6.1.5.5.7.1.1';
    private const string OID_AD_OCSP = '1.3.6.1.5.5.7.48.1';
    private const string OID_CRL_DP = '2.5.29.31';
    private const string OID_EKU = '2.5.29.37';

    private function __construct(
        private DerNode $tbsCertificate,
    ) {}

    public static function fromPem(string $pem): ?self
    {
        $der = self::pemToDer($pem);

        if ($der === null) {
            return null;
        }

        try {
            $certificate = DerDecoder::decode($der);
        } catch (Throwable) {
            return null;
        }

        $tbs = $certificate->child(0);

        if ($tbs === null || !$tbs->isSequence()) {
            return null;
        }

        return new self($tbs);
    }

    /** The raw DER of the issuer Name (for OCSP issuerNameHash / CRL issuer matching). */
    public function issuerNameDer(): string
    {
        return $this->field($this->baseOffset() + 2)?->raw ?? '';
    }

    /** The raw DER of the subject Name (to match a candidate issuer certificate). */
    public function subjectNameDer(): string
    {
        return $this->field($this->baseOffset() + 4)?->raw ?? '';
    }

    /** The serial number's INTEGER value bytes (verbatim, for the OCSP CertID). */
    public function serialNumberBytes(): string
    {
        return $this->field($this->baseOffset())?->content ?? '';
    }

    /**
     * SHA-1 of the issuer's public key BIT STRING contents (excluding the unused-
     * bits octet) — the OCSP CertID issuerKeyHash. Call on the ISSUER certificate.
     */
    public function subjectPublicKeyHash(): string
    {
        $spki = $this->field($this->baseOffset() + 5);
        $bitString = $spki?->child(1);

        if ($bitString === null || $bitString->content === '') {
            return '';
        }

        // BIT STRING content = <unused-bits octet> || key bytes.
        return sha1(substr($bitString->content, 1), true);
    }

    /** The OCSP responder URL from the Authority Information Access extension, or null. */
    public function ocspResponderUrl(): ?string
    {
        $aia = $this->extensionValue(self::OID_AIA);

        if ($aia === null) {
            return null;
        }

        try {
            $accessDescriptions = DerDecoder::decode($aia);
        } catch (Throwable) {
            return null;
        }

        foreach ($accessDescriptions->children as $description) {
            $method = $description->child(0);
            $location = $description->child(1);

            if ($method === null || $location === null || $method->tagNumber !== DerNode::TAG_OID) {
                continue;
            }

            // accessLocation is GeneralName [6] IMPLICIT IA5String (uniformResourceIdentifier).
            if (DerDecoder::oidToString($method->content) === self::OID_AD_OCSP && $location->isContextTag(6)) {
                return $location->content;
            }
        }

        return null;
    }

    /**
     * The raw DER inside an extension's extnValue OCTET STRING, or null.
     */
    public function extensionValue(string $oid): ?string
    {
        foreach ($this->tbsCertificate->children as $field) {
            if (!$field->isContextTag(3)) {
                continue;
            }

            $extensions = $field->child(0);

            if ($extensions === null || !$extensions->isSequence()) {
                return null;
            }

            foreach ($extensions->children as $extension) {
                if (!$extension->isSequence() || $extension->childCount() < 2) {
                    continue;
                }

                $idNode = $extension->child(0);

                if ($idNode === null || $idNode->tagNumber !== DerNode::TAG_OID) {
                    continue;
                }

                if (DerDecoder::oidToString($idNode->content) === $oid) {
                    return $extension->child($extension->childCount() - 1)?->content;
                }
            }
        }

        return null;
    }

    public function crlDistributionExtension(): ?string
    {
        return $this->extensionValue(self::OID_CRL_DP);
    }

    /**
     * The HTTP(S) CRL distribution point URLs from the CRL Distribution Points
     * extension (RFC 5280 §4.2.1.13), in document order.
     *
     * CRLDistributionPoints ::= SEQUENCE OF DistributionPoint, where each
     * DistributionPoint's distributionPoint [0] fullName [0] is a GeneralNames
     * whose uniformResourceIdentifier is a [6] IA5String.
     *
     * @return list<string>
     */
    public function crlDistributionUrls(): array
    {
        $extension = $this->crlDistributionExtension();

        if ($extension === null) {
            return [];
        }

        try {
            $distributionPoints = DerDecoder::decode($extension);
        } catch (Throwable) {
            return [];
        }

        $urls = [];

        foreach ($distributionPoints->children as $distributionPoint) {
            // DistributionPoint ::= SEQUENCE { distributionPoint [0] ... }
            $name = $distributionPoint->child(0);

            if ($name === null || !$name->isContextTag(0)) {
                continue;
            }

            // distributionPoint [0] DistributionPointName; fullName [0] GeneralNames.
            $fullName = $name->child(0);

            if ($fullName === null || !$fullName->isContextTag(0)) {
                continue;
            }

            foreach ($fullName->children as $generalName) {
                // uniformResourceIdentifier is GeneralName [6] IMPLICIT IA5String.
                if ($generalName->isContextTag(6) && $generalName->content !== '') {
                    $urls[] = $generalName->content;
                }
            }
        }

        return $urls;
    }

    /**
     * Whether the Extended Key Usage extension asserts the given purpose OID —
     * used to confirm a delegated OCSP responder carries id-kp-OCSPSigning
     * (RFC 6960 §4.2.2.2).
     */
    public function hasExtendedKeyUsage(string $purposeOid): bool
    {
        $eku = $this->extensionValue(self::OID_EKU);

        if ($eku === null) {
            return false;
        }

        try {
            $purposes = DerDecoder::decode($eku);
        } catch (Throwable) {
            return false;
        }

        foreach ($purposes->children as $purpose) {
            if ($purpose->tagNumber === DerNode::TAG_OID
                && DerDecoder::oidToString($purpose->content) === $purposeOid) {
                return true;
            }
        }

        return false;
    }

    /**
     * tbsCertificate field offset: 1 when the optional [0] version is present, else 0.
     */
    private function baseOffset(): int
    {
        return $this->tbsCertificate->child(0)?->isContextTag(0) === true ? 1 : 0;
    }

    private function field(int $index): ?DerNode
    {
        return $this->tbsCertificate->child($index);
    }

    private static function pemToDer(string $pem): ?string
    {
        $base64 = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem);

        if (!is_string($base64) || $base64 === '') {
            return null;
        }

        $der = base64_decode($base64, true);

        return $der === false || $der === '' ? null : $der;
    }
}
