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
use Pulsar\Extension\Auth\Social\Features\MapIdentity\MapIdentityHandler;
use Pulsar\Extension\Auth\Social\Features\MapIdentity\MapIdentityRequest;

/**
 * Top-level SSO gateway orchestrator.
 *
 * Coordinates the full OAuth callback flow: code exchange,
 * identity mapping, and account linking into a single result.
 */
final readonly class SsoGateway implements SsoGatewayInterface
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
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
