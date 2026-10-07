<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\PrivacyPass\Internal;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;

use function preg_match;
use function strcasecmp;
use function strtok;

/**
 * Extracts the redeemed token from an `Authorization: PrivateToken` header
 * (RFC 9577 §2.2.2): `Authorization: PrivateToken token="<base64url>"`.
 *
 * The token parameter is a base64url value (alphabet `A-Za-z0-9-_` plus `=`
 * padding) and may be a bare token or a quoted-string.
 */
#[Internal]
final class PrivateTokenHeaderParser
{
    /**
     * @return string|null the base64url token value, or null when absent/malformed
     */
    public static function fromRequest(ServerRequestInterface $request): ?string
    {
        foreach ($request->getHeader('Authorization') as $header) {
            $token = self::parse($header);
            if ($token !== null) {
                return $token;
            }
        }

        return null;
    }

    public static function parse(string $header): ?string
    {
        $scheme = strtok($header, " \t");
        if ($scheme === false || strcasecmp($scheme, 'PrivateToken') !== 0) {
            return null;
        }

        if (preg_match('/\btoken\s*=\s*"?([A-Za-z0-9\-_]+={0,2})"?/', $header, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
