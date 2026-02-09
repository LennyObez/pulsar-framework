<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\SocialSso\Domain\SsoLoginResult;

/**
 * Top-level gateway for social SSO login flows.
 *
 * Orchestrates the full OAuth callback: state verification, code exchange,
 * identity mapping, and account linking.
 */
#[Api(since: '1.0.0')]
interface SsoGatewayInterface
{
    /**
     * Execute the full social login flow for an OAuth callback.
     *
     * @throws \Pulsar\Extension\SocialSso\Exception\SsoException on any SSO failure
     */
    public function fullLogin(string $providerName, string $code, string $state): SsoLoginResult;
}
