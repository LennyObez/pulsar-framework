<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Internal\Certificate;

use Pulsar\Api\Internal;
use Pulsar\Extension\Psd2\Internal\Certificate\Asn1\DerDecoder;
use Pulsar\Extension\Psd2\Internal\Certificate\Asn1\DerNode;
use Throwable;

use function array_values;
use function base64_decode;
use function in_array;
use function is_string;
use function preg_replace;
use function trim;

/**
 * Parses the PSD2 attributes out of a certificate's qcStatements extension by
 * decoding the actual ASN.1 (ETSI TS 119 495), instead of string-matching the
 * certificate text.
 *
 * String-matching was unsound: a role token like "PSP_AI" could appear anywhere
 * in the certificate's fields, and the true roles/NCA data live in a structured
 * DER extension. This walks certificate -> tbsCertificate -> extensions -> the
 * qcStatements extension (OID 1.3.6.1.5.5.7.1.3) -> the PSD2 QcStatement
 * (OID 0.4.0.19495.2) -> PSD2QcType { rolesOfPSP, nCAName, nCAId }.
 */
#[Internal(reason: 'ETSI TS 119 495 PSD2 QcStatement parsing')]
final readonly class Psd2QcStatementsParser
{
    private const string QC_STATEMENTS_EXT_OID = '1.3.6.1.5.5.7.1.3';
    private const string PSD2_QC_STATEMENT_OID = '0.4.0.19495.2';
    private const string QC_COMPLIANCE_OID = '0.4.0.1862.1.1';

    /** ETSI TS 119 495 PSP role OIDs -> canonical role codes. */
    private const array ROLE_OIDS = [
        '0.4.0.19495.1.1' => 'PSP_AS',
        '0.4.0.19495.1.2' => 'PSP_PI',
        '0.4.0.19495.1.3' => 'PSP_AI',
        '0.4.0.19495.1.4' => 'PSP_IC',
    ];

    /**
     * Parse the PSD2 QcStatements from a PEM certificate. Returns null when the
     * certificate carries no qcStatements extension or it cannot be decoded
     * (treated as "no PSD2 attributes", never a fatal).
     */
    public function parseCertificate(string $pem): ?Psd2QcStatements
    {
        try {
            $der = $this->pemToDer($pem);

            if ($der === null) {
                return null;
            }

            $qcStatementsDer = $this->extractExtensionValue(DerDecoder::decode($der), self::QC_STATEMENTS_EXT_OID);

            if ($qcStatementsDer === null) {
                return null;
            }

            return $this->parseQcStatements($qcStatementsDer);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Parse a raw qcStatements extension value (the DER inside the extension's
     * OCTET STRING). Exposed for direct testing of the ASN.1 handling.
     */
    public function parseQcStatements(string $qcStatementsDer): Psd2QcStatements
    {
        $roles = [];
        $ncaName = '';
        $ncaId = '';
        $qualified = false;

        $qcStatements = DerDecoder::decode($qcStatementsDer);

        foreach ($qcStatements->children as $statement) {
            if (!$statement->isSequence()) {
                continue;
            }

            $idNode = $statement->child(0);

            if ($idNode === null || $idNode->tagNumber !== DerNode::TAG_OID) {
                continue;
            }

            $oid = DerDecoder::oidToString($idNode->content);

            if ($oid === self::QC_COMPLIANCE_OID) {
                $qualified = true;

                continue;
            }

            if ($oid === self::PSD2_QC_STATEMENT_OID) {
                $info = $statement->child(1);

                if ($info !== null && $info->isSequence()) {
                    [$roles, $ncaName, $ncaId] = $this->parsePsd2Type($info);
                }
            }
        }

        return new Psd2QcStatements($roles, $ncaName, $ncaId, $qualified);
    }

    /**
     * Parse PSD2QcType ::= SEQUENCE { rolesOfPSP, nCAName UTF8String, nCAId UTF8String }.
     *
     * @return array{0: list<string>, 1: string, 2: string}
     */
    private function parsePsd2Type(DerNode $info): array
    {
        $roles = [];
        $rolesSeq = $info->child(0);

        if ($rolesSeq !== null && $rolesSeq->isSequence()) {
            foreach ($rolesSeq->children as $role) {
                if (!$role->isSequence()) {
                    continue;
                }

                $roleOidNode = $role->child(0);

                if ($roleOidNode === null || $roleOidNode->tagNumber !== DerNode::TAG_OID) {
                    continue;
                }

                $roleOid = DerDecoder::oidToString($roleOidNode->content);
                $code = self::ROLE_OIDS[$roleOid] ?? ($role->child(1)?->content ?? '');

                if ($code !== '' && !in_array($code, $roles, true)) {
                    $roles[] = $code;
                }
            }
        }

        $ncaName = trim($info->child(1)?->content ?? '');
        $ncaId = trim($info->child(2)?->content ?? '');

        return [array_values($roles), $ncaName, $ncaId];
    }

    /**
     * Walk certificate -> tbsCertificate -> [3] extensions -> the extension with
     * $oid, returning the DER inside its extnValue OCTET STRING, or null.
     */
    private function extractExtensionValue(DerNode $certificate, string $oid): ?string
    {
        $tbs = $certificate->child(0);

        if ($tbs === null || !$tbs->isSequence()) {
            return null;
        }

        $extensions = null;

        foreach ($tbs->children as $field) {
            if ($field->isContextTag(3)) {
                $extensions = $field->child(0); // [3] EXPLICIT wraps SEQUENCE OF Extension

                break;
            }
        }

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

            if (DerDecoder::oidToString($idNode->content) !== $oid) {
                continue;
            }

            // Extension ::= SEQUENCE { extnID, critical BOOLEAN DEFAULT FALSE, extnValue OCTET STRING }.
            // extnValue is always the last element; the OCTET STRING content is the extension DER.
            return $extension->child($extension->childCount() - 1)?->content;
        }

        return null;
    }

    private function pemToDer(string $pem): ?string
    {
        $base64 = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem);

        if (!is_string($base64) || $base64 === '') {
            return null;
        }

        $der = base64_decode($base64, true);

        return $der === false || $der === '' ? null : $der;
    }
}
