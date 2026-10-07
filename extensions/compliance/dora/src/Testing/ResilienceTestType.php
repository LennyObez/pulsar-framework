<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dora\Testing;

use Pulsar\Api\Api;

/**
 * Types of digital operational resilience tests per DORA Articles 24-27.
 * @api
 */
#[Api(since: '1.0.0')]
enum ResilienceTestType: string
{
    case VulnerabilityScanning = 'vulnerability_scanning';
    case OpenSourceAnalysis = 'open_source_analysis';
    case NetworkSecurity = 'network_security';
    case PenetrationTesting = 'penetration_testing';
    case GapAnalysis = 'gap_analysis';
    case PhysicalSecurity = 'physical_security';
    case SourceCodeReview = 'source_code_review';
    case ScenarioBasedTesting = 'scenario_based_testing';
    case PerformanceTesting = 'performance_testing';
    case EndToEndTesting = 'end_to_end_testing';
    case Tlpt = 'tlpt';
}
