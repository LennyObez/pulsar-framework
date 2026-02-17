<?php

declare(strict_types=1);

namespace Pulsar\Security\SecurityTxt;

use NoDiscard;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Message\Response;

use function implode;

/**
 * Generates and serves /.well-known/security.txt per RFC 9116.
 *
 * Wire this as a route handler for GET /.well-known/security.txt.
 */
#[Api(since: '1.0.0')]
final readonly class SecurityTxtGenerator implements RequestHandlerInterface
{
    public function __construct(
        private SecurityTxtConfig $config,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Response::text($this->generate());
    }

    /**
     * Generate the security.txt content.
     */
    #[NoDiscard]
    public function generate(): string
    {
        $lines = [];

        foreach ($this->config->contacts as $contact) {
            $lines[] = 'Contact: ' . $contact;
        }

        if ($this->config->expires !== '') {
            $lines[] = 'Expires: ' . $this->config->expires;
        }

        if ($this->config->encryption !== '') {
            $lines[] = 'Encryption: ' . $this->config->encryption;
        }

        if ($this->config->acknowledgments !== '') {
            $lines[] = 'Acknowledgments: ' . $this->config->acknowledgments;
        }

        if ($this->config->policy !== '') {
            $lines[] = 'Policy: ' . $this->config->policy;
        }

        foreach ($this->config->hiring as $uri) {
            $lines[] = 'Hiring: ' . $uri;
        }

        if ($this->config->preferredLanguages !== []) {
            $lines[] = 'Preferred-Languages: ' . implode(', ', $this->config->preferredLanguages);
        }

        if ($this->config->canonical !== '') {
            $lines[] = 'Canonical: ' . $this->config->canonical;
        }

        return implode("\n", $lines) . "\n";
    }
}
