<?php

declare(strict_types=1);

namespace Pulsar\Extension\DataAct\Access;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\DataAct\Config\DataActConfig;

use function in_array;

/**
 * Third-party data access policy per Data Act Article 6.
 *
 * Evaluates whether a third-party data access request meets the
 * conditions for authorized access, including purpose limitation,
 * data minimization, and contractual basis.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ThirdPartyAccessPolicy
{
    /** @var list<string> Purposes that are always denied. */
    private const array DENIED_PURPOSES = [
        'profiling',
        'advertising',
        'surveillance',
    ];

    public function __construct(
        private DataActConfig $config,
    ) {}

    /**
     * Evaluate a third-party access request against the policy.
     *
     * @param string $requestingParty  Identifier of the requesting third party
     * @param string $dataSubjectId    Identifier of the data subject
     * @param string $purpose          Declared purpose for the data access
     */
    #[NoDiscard]
    public function evaluate(
        string $requestingParty,
        string $dataSubjectId,
        string $purpose,
    ): ThirdPartyAccessResult {
        if (!$this->config->enabled) {
            return ThirdPartyAccessResult::denied('Data Act compliance is not enabled');
        }

        if ($requestingParty === '' || $dataSubjectId === '') {
            return ThirdPartyAccessResult::denied('Requesting party and data subject must be identified');
        }

        if ($purpose === '') {
            return ThirdPartyAccessResult::denied('Purpose must be specified per Art. 6(2)(b)');
        }

        if (in_array($purpose, self::DENIED_PURPOSES, true)) {
            return ThirdPartyAccessResult::denied(
                'Purpose "' . $purpose . '" is prohibited under Art. 6(2)(e)',
            );
        }

        return ThirdPartyAccessResult::granted($requestingParty, $dataSubjectId, $purpose);
    }
}
