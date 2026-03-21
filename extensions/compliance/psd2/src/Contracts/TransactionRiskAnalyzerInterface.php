<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Psd2\Domain\TransactionRiskAssessment;
use Pulsar\Extension\Psd2\Exception\Psd2Exception;

/**
 * Transaction risk analysis per PSD2 RTS Art. 18.
 *
 * Performs fraud scoring using velocity checks, amount thresholds,
 * and configurable anomaly detection rules to determine whether
 * SCA is required or an exemption applies.
 * @api
 */
#[Api(since: '1.0.0')]
interface TransactionRiskAnalyzerInterface
{
    /**
     * Analyze a transaction for risk and determine SCA requirements.
     *
     * @param string $identityId The identity initiating the transaction
     * @param string $transactionId Unique transaction identifier
     * @param int $amountMinorUnits Transaction amount in minor currency units
     * @param string $currency ISO 4217 currency code
     * @param string $payeeId Payee identifier
     * @param array<string, mixed> $context Additional context for risk rules
     *
     * @throws Psd2Exception On analysis failure
     */
    public function analyze(
        string $identityId,
        string $transactionId,
        int $amountMinorUnits,
        string $currency,
        string $payeeId,
        array $context = [],
    ): TransactionRiskAssessment;
}
