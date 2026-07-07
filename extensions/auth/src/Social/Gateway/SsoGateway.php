<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Social\Gateway;

use Override;
use Pulsar\Extension\Auth\Social\Contracts\SocialIdentityLinkerInterface;
use Pulsar\Extension\Auth\Social\Contracts\SsoGatewayInterface;
use Pulsar\Extension\Auth\Social\Domain\SsoLoginResult;
use Pulsar\Extension\Auth\Social\Exception\SsoException;
use Pulsar\Extension\Auth\Social\Features\ExchangeCode\ExchangeCodeHandler;
use Pulsar\Extension\Auth\Social\Features\ExchangeCode\ExchangeCodeRequest;
use Pulsar\Extension\Auth\Social\Features\InitiateLogin\InitiateLoginHandler;
use Pulsar\Extension\Auth\Social\Features\InitiateLogin\InitiateLoginRequest;
use Pulsar\Extension\Auth\Social\Features\InitiateLogin\InitiateLoginResult;
use Pulsar\Extension\Auth\Social\Features\MapIdentity\MapIdentityHandler;
use Pulsar\Extension\Auth\Social\Features\MapIdentity\MapIdentityRequest;

/**
 * Top-level SSO gateway orchestrator.
 *
 * Coordinates both legs of the OAuth flow: the initiate step (build the
 * provider authorization URL) and the callback (code exchange, identity
 * mapping, and account linking into a single result).
 */
final readonly class SsoGateway implements SsoGatewayInterface
{
    public function __construct(
        private InitiateLoginHandler $initiateHandler,
        private ExchangeCodeHandler $exchangeHandler,
        private MapIdentityHandler $mapHandler,
        private SocialIdentityLinkerInterface $linker,
    ) {}

    /**
     * @throws SsoException
     */
    #[Override]
    public function initiate(string $providerName, ?string $redirectUri = null): InitiateLoginResult
    {
        return $this->initiateHandler->handle(
            new InitiateLoginRequest(
                providerName: $providerName,
                redirectUri: $redirectUri,
            ),
        );
    }

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
