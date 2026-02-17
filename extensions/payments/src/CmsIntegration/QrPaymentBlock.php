<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\CmsIntegration;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeInterface;
use Pulsar\Extension\Payments\Config\PayconiqConfig;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Gateway\QrPaymentGateway;
use Pulsar\Support\QrCodeEncoder;

use function htmlspecialchars;
use function in_array;
use function is_int;
use function is_numeric;
use function is_string;

use const ENT_QUOTES;

/**
 * CMS block that displays a QR code for payment.
 *
 * Renders a configurable QR payment code in the page builder,
 * supporting EPC QR (all EU banking apps), Payconiq, and generic
 * payment links.
 */
#[Internal]
final readonly class QrPaymentBlock implements BlockTypeInterface
{
    private const array VALID_FORMATS = ['epc_qr', 'payconiq', 'payment_link'];
    private const array VALID_ALIGNMENTS = ['left', 'center', 'right'];

    public function __construct(
        private QrCodeEncoder $qrEncoder,
        private PayconiqConfig $payconiqConfig,
    ) {}

    #[Override]
    public function type(): string
    {
        return 'qr-payment';
    }

    #[Override]
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'format' => [
                    'type' => 'string',
                    'enum' => self::VALID_FORMATS,
                    'description' => 'QR code format: epc_qr, payconiq, or payment_link',
                ],
                'amount' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Amount in minor currency units (e.g., cents)',
                ],
                'currency' => [
                    'type' => 'string',
                    'description' => 'ISO 4217 currency code',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Payment description displayed below QR code',
                ],
                'beneficiary_name' => [
                    'type' => 'string',
                    'description' => 'Payee name (required for EPC QR)',
                ],
                'iban' => [
                    'type' => 'string',
                    'description' => 'Payee IBAN (required for EPC QR)',
                ],
                'bic' => [
                    'type' => 'string',
                    'description' => 'Payee BIC/SWIFT (optional for EPC QR)',
                ],
                'reference' => [
                    'type' => 'string',
                    'description' => 'Payment reference',
                ],
                'payment_url' => [
                    'type' => 'string',
                    'description' => 'Payment URL (required for payment_link format)',
                ],
                'alignment' => [
                    'type' => 'string',
                    'enum' => self::VALID_ALIGNMENTS,
                ],
            ],
            'required' => ['format', 'amount', 'currency'],
        ];
    }

    #[Override]
    public function render(array $data): string
    {
        $format = is_string($data['format'] ?? null) ? $data['format'] : 'epc_qr';
        $description = htmlspecialchars(
            is_string($data['description'] ?? null) ? $data['description'] : '',
            ENT_QUOTES,
            'UTF-8',
        );
        $alignment = is_string($data['alignment'] ?? null) && in_array($data['alignment'], self::VALID_ALIGNMENTS, true)
            ? $data['alignment']
            : 'center';

        $gateway = new QrPaymentGateway($this->qrEncoder, $this->payconiqConfig);
        $svgContent = $this->generateQrSvg($gateway, $data, $format);

        $amountDisplay = $this->formatAmountDisplay($data);
        $escapedAmount = htmlspecialchars($amountDisplay, ENT_QUOTES, 'UTF-8');

        $html = "<div class=\"qr-payment-block qr-payment-block--$alignment\">";
        $html .= '<div class="qr-payment-block__code">' . $svgContent . '</div>';

        if ($description !== '') {
            $html .= "<p class=\"qr-payment-block__description\">$description</p>";
        }

        if ($escapedAmount !== '') {
            $html .= "<p class=\"qr-payment-block__amount\">$escapedAmount</p>";
        }

        return $html . '</div>';
    }

    #[Override]
    public function validate(array $data): array
    {
        $errors = [];

        if (!isset($data['format']) || !is_string($data['format'])) {
            $errors[] = 'format is required and must be a string';
        } elseif (!in_array($data['format'], self::VALID_FORMATS, true)) {
            $errors[] = 'format must be one of: epc_qr, payconiq, payment_link';
        }

        if (!isset($data['amount'])) {
            $errors[] = 'amount is required';
        } elseif (!is_int($data['amount']) || $data['amount'] < 1) {
            $errors[] = 'amount must be a positive integer (minor currency units)';
        }

        if (!isset($data['currency']) || !is_string($data['currency'])) {
            $errors[] = 'currency is required and must be a string';
        }

        $format = is_string($data['format'] ?? null) ? $data['format'] : '';

        if ($format === 'epc_qr') {
            if (!isset($data['beneficiary_name']) || !is_string($data['beneficiary_name']) || $data['beneficiary_name'] === '') {
                $errors[] = 'beneficiary_name is required for EPC QR format';
            }

            if (!isset($data['iban']) || !is_string($data['iban']) || $data['iban'] === '') {
                $errors[] = 'iban is required for EPC QR format';
            }
        }

        if ($format === 'payment_link') {
            if (!isset($data['payment_url']) || !is_string($data['payment_url']) || $data['payment_url'] === '') {
                $errors[] = 'payment_url is required for payment_link format';
            }
        }

        if (isset($data['alignment'])) {
            if (!is_string($data['alignment'])) {
                $errors[] = 'alignment must be a string';
            } elseif (!in_array($data['alignment'], self::VALID_ALIGNMENTS, true)) {
                $errors[] = 'alignment must be one of: left, center, right';
            }
        }

        return $errors;
    }

    /**
     * Generate the QR code SVG content based on format.
     *
     * @param array<string, mixed> $data
     */
    private function generateQrSvg(QrPaymentGateway $gateway, array $data, string $format): string
    {
        $amount = $this->resolveAmount($data);

        return match ($format) {
            'epc_qr' => $gateway->generateEpcQr(
                amount: $amount,
                beneficiaryName: is_string($data['beneficiary_name'] ?? null) ? $data['beneficiary_name'] : '',
                iban: is_string($data['iban'] ?? null) ? $data['iban'] : '',
                bic: is_string($data['bic'] ?? null) ? $data['bic'] : '',
                reference: is_string($data['reference'] ?? null) ? $data['reference'] : '',
            )->svgContent,
            'payconiq' => $gateway->generatePayconiqQr(
                amount: $amount,
                paymentId: is_string($data['reference'] ?? null) ? $data['reference'] : 'block-payment',
                reference: is_string($data['reference'] ?? null) ? $data['reference'] : '',
            )->svgContent,
            'payment_link' => $gateway->generatePaymentLinkQr(
                amount: $amount,
                paymentUrl: is_string($data['payment_url'] ?? null) ? $data['payment_url'] : '',
                reference: is_string($data['reference'] ?? null) ? $data['reference'] : '',
            )->svgContent,
            default => '',
        };
    }

    /**
     * Resolve Money from block data.
     *
     * @param array<string, mixed> $data
     */
    private function resolveAmount(array $data): Money
    {
        $rawAmount = $data['amount'] ?? 0;
        $amountValue = is_int($rawAmount) ? $rawAmount : (is_numeric($rawAmount) ? (int) $rawAmount : 0);
        $currencyStr = is_string($data['currency'] ?? null) ? $data['currency'] : 'EUR';
        $currency = Currency::tryFrom($currencyStr) ?? Currency::EUR;

        return Money::of($amountValue > 0 ? $amountValue : 1, $currency);
    }

    /**
     * Format the amount for display.
     *
     * @param array<string, mixed> $data
     */
    private function formatAmountDisplay(array $data): string
    {
        $rawAmount = $data['amount'] ?? null;

        if (!is_int($rawAmount) || $rawAmount <= 0) {
            return '';
        }

        $currencyStr = is_string($data['currency'] ?? null) ? $data['currency'] : 'EUR';
        $currency = Currency::tryFrom($currencyStr) ?? Currency::EUR;
        $money = Money::of($rawAmount, $currency);

        return $currency->symbol() . $money->format();
    }
}
