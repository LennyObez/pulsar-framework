<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Smart;

use Pulsar\Api\Api;

/**
 * SMART on FHIR well-known configuration.
 *
 * Published at `/.well-known/smart-configuration` to enable SMART app launch.
 *
 * @see http://www.hl7.org/fhir/smart-app-launch/conformance.html
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SmartConfiguration
{
    /**
     * @param list<string> $scopesSupported          OAuth2 scopes supported
     * @param list<string> $responseTypesSupported   OAuth2 response types
     * @param list<string> $grantTypesSupported      OAuth2 grant types
     * @param list<string> $capabilities             SMART capabilities
     * @param list<string> $codeChallengeMethodsSupported PKCE methods
     */
    public function __construct(
        public string $issuer,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public ?string $registrationEndpoint = null,
        public ?string $managementEndpoint = null,
        public ?string $introspectionEndpoint = null,
        public ?string $revocationEndpoint = null,
        public array $scopesSupported = [],
        public array $responseTypesSupported = ['code'],
        public array $grantTypesSupported = ['authorization_code'],
        public array $capabilities = [],
        public array $codeChallengeMethodsSupported = ['S256'],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'issuer' => $this->issuer,
            'authorization_endpoint' => $this->authorizationEndpoint,
            'token_endpoint' => $this->tokenEndpoint,
        ];

        if ($this->registrationEndpoint !== null) {
            $data['registration_endpoint'] = $this->registrationEndpoint;
        }

        if ($this->managementEndpoint !== null) {
            $data['management_endpoint'] = $this->managementEndpoint;
        }

        if ($this->introspectionEndpoint !== null) {
            $data['introspection_endpoint'] = $this->introspectionEndpoint;
        }

        if ($this->revocationEndpoint !== null) {
            $data['revocation_endpoint'] = $this->revocationEndpoint;
        }

        if ($this->scopesSupported !== []) {
            $data['scopes_supported'] = $this->scopesSupported;
        }

        $data['response_types_supported'] = $this->responseTypesSupported;
        $data['grant_types_supported'] = $this->grantTypesSupported;

        if ($this->capabilities !== []) {
            $data['capabilities'] = $this->capabilities;
        }

        $data['code_challenge_methods_supported'] = $this->codeChallengeMethodsSupported;

        return $data;
    }
}
