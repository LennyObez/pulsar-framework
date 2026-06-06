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
use function is_float;
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
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
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

    /**
     * @param array{format?: string, description?: string, alignment?: string, amount?: int, currency?: string, beneficiary_name?: string, iban?: string, payment_url?: string} $data
     */
    #[Override]
    public function render(array $data): string
    {
        $rawFormat = $data['format'] ?? null;
        $format = is_string($rawFormat) ? $rawFormat : 'epc_qr';
        $rawDescription = $data['description'] ?? null;
        $description = htmlspecialchars(
            is_string($rawDescription) ? $rawDescription : '',
            ENT_QUOTES,
            'UTF-8',
        );
        $rawAlignment = $data['alignment'] ?? null;
        $alignment = is_string($rawAlignment) && in_array($rawAlignment, self::VALID_ALIGNMENTS, true)
            ? $rawAlignment
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

        /** @var mixed $rawFormat */
        $rawFormat = $data['format'] ?? null;
        $format = is_string($rawFormat) ? $rawFormat : '';

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
        /** @var mixed $rawBeneficiary */
        $rawBeneficiary = $data['beneficiary_name'] ?? null;
        $beneficiaryName = is_string($rawBeneficiary) ? $rawBeneficiary : '';
        /** @var mixed $rawIban */
        $rawIban = $data['iban'] ?? null;
        $iban = is_string($rawIban) ? $rawIban : '';
        /** @var mixed $rawBic */
        $rawBic = $data['bic'] ?? null;
        $bic = is_string($rawBic) ? $rawBic : '';
        /** @var mixed $rawReference */
        $rawReference = $data['reference'] ?? null;
        $reference = is_string($rawReference) ? $rawReference : '';
        /** @var mixed $rawPaymentUrl */
        $rawPaymentUrl = $data['payment_url'] ?? null;
        $paymentUrl = is_string($rawPaymentUrl) ? $rawPaymentUrl : '';

        return match ($format) {
            'epc_qr' => $gateway->generateEpcQr(
                amount: $amount,
                beneficiaryName: $beneficiaryName,
                iban: $iban,
                bic: $bic,
                reference: $reference,
            )->svgContent,
            'payconiq' => $gateway->generatePayconiqQr(
                amount: $amount,
                paymentId: $reference !== '' ? $reference : 'block-payment',
                reference: $reference,
            )->svgContent,
            'payment_link' => $gateway->generatePaymentLinkQr(
                amount: $amount,
                paymentUrl: $paymentUrl,
                reference: $reference,
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
        /** @var mixed $rawAmount */
        $rawAmount = $data['amount'] ?? 0;
        $amountValue = is_int($rawAmount)
            ? $rawAmount
            : ((is_string($rawAmount) || is_float($rawAmount)) && is_numeric($rawAmount) ? (int) $rawAmount : 0);
        /** @var mixed $rawCurrency */
        $rawCurrency = $data['currency'] ?? null;
        $currencyStr = is_string($rawCurrency) ? $rawCurrency : 'EUR';
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
        /** @var mixed $rawAmount */
        $rawAmount = $data['amount'] ?? null;

        if (!is_int($rawAmount) || $rawAmount <= 0) {
            return '';
        }

        /** @var mixed $rawCurrency */
        $rawCurrency = $data['currency'] ?? null;
        $currencyStr = is_string($rawCurrency) ? $rawCurrency : 'EUR';
        $currency = Currency::tryFrom($currencyStr) ?? Currency::EUR;
        $money = Money::of($rawAmount, $currency);

        return $currency->symbol() . $money->format();
    }
}
