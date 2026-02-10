<?php

declare(strict_types=1);

namespace Pulsar\Security\Session\Validator;

use Override;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Security\Session\SessionMetadata;

use function implode;
use function preg_match_all;
use function sort;
use function sprintf;

/**
 * Validates that the request user agent matches the stored session metadata.
 *
 * Supports strict (exact match) and normalized (stable token extraction) modes.
 */
#[Internal]
final readonly class UserAgentValidator implements SessionValidatorInterface
{
    public function __construct(
        private string $mode = 'normalized',
    ) {}

    #[Override]
    public function validate(SessionMetadata $metadata, ServerRequestInterface $request): bool
    {
        $storedUa = $metadata->userAgent;
        $currentUa = $request->getHeaderLine('User-Agent');

        if ($this->mode === 'strict') {
            return $storedUa === $currentUa;
        }

        return $this->normalize($storedUa) === $this->normalize($currentUa);
    }

    #[Override]
    public function getName(): string
    {
        return 'user_agent';
    }

    private function normalize(string $ua): string
    {
        if (preg_match_all('/([A-Za-z]+)\/(\d+)/', $ua, $matches) === 0) {
            return '';
        }

        $tokens = [];
        $names = $matches[1];
        $versions = $matches[2];

        foreach ($names as $index => $name) {
            $tokens[] = sprintf('%s/%s', $name, $versions[$index]);
        }

        sort($tokens);

        return implode(';', $tokens);
    }
}
