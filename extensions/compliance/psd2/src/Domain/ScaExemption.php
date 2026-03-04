<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Domain;

use Pulsar\Api\Api;

/**
 * SCA exemptions allowed under PSD2 RTS Art. 10-18.
 */
#[Api(since: '1.0.0')]
enum ScaExemption: string
{
    case LowValueTransaction = 'low_value';
    case TrustedBeneficiary = 'trusted_beneficiary';
    case RecurringPayment = 'recurring_payment';
    case TransactionRiskAnalysis = 'transaction_risk_analysis';
    case ContactlessPayment = 'contactless';
    case CorporatePayment = 'corporate_payment';
    case None = 'none';
}
