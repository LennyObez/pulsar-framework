<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dora\Risk;

use Pulsar\Api\Api;

/**
 * ICT risk categories per DORA Article 5-16.
 */
#[Api(since: '1.0.0')]
enum IctRiskCategory: string
{
    case CyberAttack = 'cyber_attack';
    case SystemFailure = 'system_failure';
    case ThirdPartyDependency = 'third_party_dependency';
    case DataBreach = 'data_breach';
    case InsiderThreat = 'insider_threat';
    case NaturalDisaster = 'natural_disaster';
    case SupplyChain = 'supply_chain';
    case ConcentrationRisk = 'concentration_risk';
}
