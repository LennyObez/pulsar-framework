<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Gateway;

use Override;
use Pulsar\Extension\SocialSso\Contracts\SocialIdentityLinkerInterface;
use Pulsar\Extension\SocialSso\Contracts\SsoGatewayInterface;
use Pulsar\Extension\SocialSso\Domain\SsoLoginResult;
use Pulsar\Extension\SocialSso\Exception\SsoException;
use Pulsar\Extension\SocialSso\Features\ExchangeCode\ExchangeCodeHandler;
use Pulsar\Extension\SocialSso\Features\ExchangeCode\ExchangeCodeRequest;
use Pulsar\Extension\SocialSso\Features\MapIdentity\MapIdentityHandler;
use Pulsar\Extension\SocialSso\Features\MapIdentity\MapIdentityRequest;

/**
 * Top-level SSO gateway orchestrator.
 *
 * Coordinates the full OAuth callback flow: code exchange,
 * identity mapping, and account linking into a single result.
 */
final readonly class SsoGateway implements SsoGatewayInterface
{
    public function __construct(
        private ExchangeCodeHandler $exchangeHandler,
        private MapIdentityHandler $mapHandler,
        private SocialIdentityLinkerInterface $linker,
    ) {}

    /**
     * @throws SsoException
     */
    #[Override]
    public function fullLogin(string $providerName, string $code, string $state): SsoLoginResult
    {
        $exchangeResult = $this->exchangeHandler->handle(
            new ExchangeCodeRequest(
                providerName: $providerName,
                code: $code,
                state: $state,
            ),
        );

        $mapResult = $this->mapHandler->handle(
            new MapIdentityRequest(
                tokenSet: $exchangeResult->tokenSet,
                providerName: $providerName,
            ),
        );

        $linkResult = $this->linker->link($mapResult->socialIdentity);

        return new SsoLoginResult(
            socialIdentity: $mapResult->socialIdentity,
            linkResult: $linkResult,
            verifiedClaims: $exchangeResult->verifiedClaims,
        );
    }
}
