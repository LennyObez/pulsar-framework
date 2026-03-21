<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Social\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Auth\Social\Domain\SsoLoginResult;
use Pulsar\Extension\Auth\Social\Exception\SsoException;

/**
 * Top-level gateway for social SSO login flows.
 *
 * Orchestrates the full OAuth callback: state verification, code exchange,
 * identity mapping, and account linking.
 * @api
 */
#[Api(since: '1.0.0')]
interface SsoGatewayInterface
{
    /**
     * Execute the full social login flow for an OAuth callback.
     *
     * @throws SsoException on any SSO failure
     */
    public function fullLogin(string $providerName, string $code, string $state): SsoLoginResult;
}
