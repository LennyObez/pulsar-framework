<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Gateway;

use Pulsar\Api\Api;
use Pulsar\Extension\Payments\Config\PayconiqConfig;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\QrPaymentData;
use Pulsar\Extension\Payments\Domain\QrPaymentFormat;
use Pulsar\Extension\Payments\Exception\PaymentException;
use Pulsar\Support\QrCodeEncoder;

use function sprintf;
use function str_replace;

/**
 * QR code payment gateway.
 *
 * Generates payment QR codes in multiple formats:
 * - EPC QR: European Payments Council standard (works with all EU banking apps)
 * - Payconiq: Belgian/Benelux mobile payment QR codes
 * - Payment Link: Generic URL payment links encoded as QR
 *
 * Uses the existing CMS QrCodeEncoder for SVG generation.
 */
#[Api(since: '1.0.0')]
final readonly class QrPaymentGateway
{
    public function __construct(
        private QrCodeEncoder $qrEncoder,
        private PayconiqConfig $payconiqConfig,
    ) {}

    /**
     * Generate a QR code for an EPC (European Payments Council) credit transfer.
     *
     * Produces a standardized QR code per EPC069-12 that can be scanned by
     * any EU banking app supporting SCT (SEPA Credit Transfer).
     *
     * @param string $beneficiaryName Payee name (max 70 chars)
     * @param string $iban Payee IBAN
     * @param string $bic Payee BIC/SWIFT (optional, 8 or 11 chars)
     * @param string $reference Payment reference (max 140 chars)
     * @param string $information Remittance information (max 70 chars)
     *
     * @throws PaymentException If the currency is not EUR
     */
    public function generateEpcQr(
        Money $amount,
        string $beneficiaryName,
        string $iban,
        string $bic = '',
        string $reference = '',
        string $information = '',
    ): QrPaymentData {
        if ($amount->currency !== Currency::EUR) {
            throw PaymentException::invalid('EPC QR codes only support EUR');
        }

        $payload = $this->buildEpcPayload(
            $amount,
            $beneficiaryName,
            $iban,
            $bic,
            $reference,
            $information,
        );

        $svg = $this->qrEncoder->encode($payload, moduleSize: 4, quietZone: 4);

        return new QrPaymentData(
            format: QrPaymentFormat::EpcQr,
            payload: $payload,
            svgContent: $svg,
            amount: $amount,
            reference: $reference,
        );
    }

    /**
     * Generate a Payconiq payment QR code.
     *
     * @param string $paymentId The Payconiq payment ID from createIntent()
     * @param string $reference Payment reference
     *
     * @throws PaymentException If Payconiq is not configured
     */
    public function generatePayconiqQr(
        Money $amount,
        string $paymentId,
        string $reference = '',
    ): QrPaymentData {
        if ($this->payconiqConfig->merchantId === '') {
            throw PaymentException::invalid('Payconiq merchant ID not configured');
        }

        $payload = sprintf(
            'https://payconiq.com/pay/2/%s/%s',
            $this->payconiqConfig->merchantId,
            $paymentId,
        );

        $svg = $this->qrEncoder->encode($payload, moduleSize: 4, quietZone: 4);

        return new QrPaymentData(
            format: QrPaymentFormat::Payconiq,
            payload: $payload,
            svgContent: $svg,
            amount: $amount,
            reference: $reference,
        );
    }

    /**
     * Generate a generic payment link QR code.
     *
     * Encodes any payment URL as a QR code for display to customers.
     *
     * @param string $paymentUrl The payment URL to encode
     * @param string $reference Payment reference
     */
    public function generatePaymentLinkQr(
        Money $amount,
        string $paymentUrl,
        string $reference = '',
    ): QrPaymentData {
        $svg = $this->qrEncoder->encode($paymentUrl, moduleSize: 4, quietZone: 4);

        return new QrPaymentData(
            format: QrPaymentFormat::PaymentLink,
            payload: $paymentUrl,
            svgContent: $svg,
            amount: $amount,
            reference: $reference,
        );
    }

    /**
     * Build the EPC QR code payload per EPC069-12 v2.1 specification.
     *
     * Format:
     * BCD (Service Tag)
     * 002 (Version)
     * 1 (Character set: UTF-8)
     * SCT (Identification)
     * BIC (optional)
     * Beneficiary name
     * IBAN
     * EUR amount (e.g., EUR12.50)
     * Purpose code (empty)
     * Reference
     * Information
     */
    private function buildEpcPayload(
        Money $amount,
        string $beneficiaryName,
        string $iban,
        string $bic,
        string $reference,
        string $information,
    ): string {
        $formattedAmount = 'EUR' . $amount->format();
        $cleanIban = str_replace(' ', '', $iban);

        $lines = [
            'BCD',
            '002',
            '1',
            'SCT',
            $bic,
            $beneficiaryName,
            $cleanIban,
            $formattedAmount,
            '',
            $reference,
            $information,
        ];

        return implode("\n", $lines);
    }
}
