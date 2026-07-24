<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Tests\Unit\Internal\Certificate;

use OpenSSLAsymmetricKey;
use OpenSSLCertificateSigningRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psd2\Internal\Certificate\Asn1\DerDecoder;
use Pulsar\Extension\Psd2\Internal\Certificate\Asn1\DerNode;
use Pulsar\Extension\Psd2\Internal\Certificate\Psd2QcStatementsParser;

use function array_map;
use function chr;
use function count;
use function explode;
use function implode;
use function intval;
use function openssl_csr_new;
use function openssl_csr_sign;
use function openssl_pkey_new;
use function openssl_x509_export;
use function strlen;

use const OPENSSL_KEYTYPE_EC;

#[CoversClass(Psd2QcStatementsParser::class)]
#[CoversClass(DerDecoder::class)]
#[CoversClass(DerNode::class)]
final class Psd2QcStatementsParserTest extends TestCase
{
    #[Test]
    public function parsesRolesNcaAndQualifiedFromRealAsn1(): void
    {
        $qcStatements = $this->seq(
            // QcCompliance (qualified marker)
            $this->seq($this->oid('0.4.0.1862.1.1')),
            // PSD2 QcStatement { OID, PSD2QcType { rolesOfPSP, ncaName, ncaId } }
            $this->seq(
                $this->oid('0.4.0.19495.2'),
                $this->seq(
                    $this->seq(
                        $this->seq($this->oid('0.4.0.19495.1.3'), $this->utf8('PSP_AI')),
                        $this->seq($this->oid('0.4.0.19495.1.2'), $this->utf8('PSP_PI')),
                    ),
                    $this->utf8('Financial Conduct Authority'),
                    $this->utf8('GB-FCA'),
                ),
            ),
        );

        $result = new Psd2QcStatementsParser()->parseQcStatements($qcStatements);

        self::assertSame(['PSP_AI', 'PSP_PI'], $result->roles);
        self::assertSame('Financial Conduct Authority', $result->ncaName);
        self::assertSame('GB-FCA', $result->ncaId);
        self::assertTrue($result->qualified);
    }

    #[Test]
    public function returnsNoRolesWhenOnlyQcComplianceIsPresent(): void
    {
        $qcStatements = $this->seq($this->seq($this->oid('0.4.0.1862.1.1')));

        $result = new Psd2QcStatementsParser()->parseQcStatements($qcStatements);

        self::assertSame([], $result->roles);
        self::assertTrue($result->qualified);
        self::assertSame('', $result->ncaName);
    }

    #[Test]
    public function fallsBackToTheRoleNameForAnUnknownRoleOid(): void
    {
        $qcStatements = $this->seq(
            $this->seq(
                $this->oid('0.4.0.19495.2'),
                $this->seq(
                    $this->seq(
                        $this->seq($this->oid('0.4.0.19495.1.99'), $this->utf8('PSP_XX')),
                    ),
                    $this->utf8('NCA'),
                    $this->utf8('XX-NCA'),
                ),
            ),
        );

        $result = new Psd2QcStatementsParser()->parseQcStatements($qcStatements);

        self::assertSame(['PSP_XX'], $result->roles);
        self::assertFalse($result->qualified);
    }

    #[Test]
    public function returnsNullForACertificateWithoutAQcStatementsExtension(): void
    {
        // A plain self-signed certificate carries no qcStatements extension.
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
        $csr = openssl_csr_new(['commonName' => 'Plain Cert'], $key, ['digest_alg' => 'sha256']);
        self::assertInstanceOf(OpenSSLCertificateSigningRequest::class, $csr);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        self::assertNotFalse($cert);
        $pem = '';
        self::assertTrue(openssl_x509_export($cert, $pem));

        self::assertNull(new Psd2QcStatementsParser()->parseCertificate($pem));
    }

    // ── DER encoding helpers (build valid qcStatements to decode) ─────────────

    private function seq(string ...$children): string
    {
        return $this->tlv(0x30, implode('', $children));
    }

    private function utf8(string $value): string
    {
        return $this->tlv(0x0C, $value);
    }

    private function oid(string $dotted): string
    {
        /** @var list<int> $parts */
        $parts = array_map(intval(...), explode('.', $dotted));
        $bytes = chr(40 * $parts[0] + $parts[1]);

        for ($i = 2, $n = count($parts); $i < $n; $i++) {
            $bytes .= $this->base128($parts[$i]);
        }

        return $this->tlv(0x06, $bytes);
    }

    private function base128(int $value): string
    {
        $out = chr($value & 0x7F);
        $value >>= 7;

        while ($value > 0) {
            $out = chr(0x80 | ($value & 0x7F)) . $out;
            $value >>= 7;
        }

        return $out;
    }

    private function tlv(int $tag, string $content): string
    {
        return chr($tag) . $this->length(strlen($content)) . $content;
    }

    private function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = '';

        while ($length > 0) {
            $bytes = chr($length & 0xFF) . $bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }
}
