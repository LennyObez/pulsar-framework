<?php

declare(strict_types=1);

namespace Pulsar\Security\ThreatDetection;

use Pulsar\Api\Api;

/**
 * Classification of detected threat types.
 */
#[Api(since: '1.0.0')]
enum ThreatCategory: string
{
    case BruteForce = 'brute_force';
    case CredentialStuffing = 'credential_stuffing';
    case InjectionAttempt = 'injection_attempt';
    case GeoAnomaly = 'geo_anomaly';
    case ApiAbuse = 'api_abuse';

    /** Reconnaissance/scanning activity (honeypots, path enumeration). */
    case Reconnaissance = 'reconnaissance';

    /** Request tampering or signature mismatch. */
    case RequestTampering = 'request_tampering';

    /** Canary token triggered: data leak detected. */
    case DataLeak = 'data_leak';

    /** Session hijacking detected (IP/UA change mid-session). */
    case SessionHijack = 'session_hijack';

    /** Account takeover attempt (credential change from new device/IP). */
    case AccountTakeover = 'account_takeover';

    /** Automated bot traffic detected. */
    case BotTraffic = 'bot_traffic';
}
