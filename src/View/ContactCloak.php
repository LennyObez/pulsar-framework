<?php

declare(strict_types=1);

namespace Pulsar\View;

use NoDiscard;
use Pulsar\Api\Api;

use function base64_encode;
use function htmlspecialchars;
use function implode;
use function is_scalar;
use function ltrim;
use function sprintf;
use function trim;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

/**
 * Renders anti-scraping contact links whose address never appears literally in
 * the served HTML.
 *
 * An e-mail is split into base64-encoded `data-u` / `data-d` attributes and a
 * phone number into `data-n`, so no `user@domain` or dialable string is present
 * for a scraper's regex to lift. The bundled `contact-cloak.js` (CSP
 * `script-src 'self'` clean, no dependencies) reassembles the `mailto:` / `tel:`
 * href and the visible text on load; with JavaScript disabled the element stays
 * readable, non-linked fallback text — never a broken link.
 * @api
 */
#[Api(since: '1.0.0')]
final class ContactCloak
{
    /**
     * Cloak an e-mail address.
     *
     * @param array<string, mixed> $attrs Extra HTML attributes; `text` sets the
     *     no-JavaScript fallback label (defaults to "email"), `class` is merged
     *     with the `pulsar-cloak-mail` marker class.
     */
    #[NoDiscard]
    public static function mail(string $user, string $domain, array $attrs = []): string
    {
        return self::anchor(
            'pulsar-cloak-mail',
            [
                'data-u' => base64_encode($user),
                'data-d' => base64_encode($domain),
            ],
            $attrs,
            'email',
        );
    }

    /**
     * Cloak a telephone number.
     *
     * @param array<string, mixed> $attrs Extra HTML attributes; `text` sets the
     *     no-JavaScript fallback label (defaults to "call"), `class` is merged
     *     with the `pulsar-cloak-tel` marker class.
     */
    #[NoDiscard]
    public static function tel(string $number, array $attrs = []): string
    {
        return self::anchor(
            'pulsar-cloak-tel',
            ['data-n' => base64_encode($number)],
            $attrs,
            'call',
        );
    }

    /**
     * @param array<string, string> $dataAttrs Encoded payload attributes
     * @param array<string, mixed>  $attrs     Caller-supplied attributes
     */
    private static function anchor(string $markerClass, array $dataAttrs, array $attrs, string $defaultText): string
    {
        $text = isset($attrs['text']) && is_scalar($attrs['text']) ? (string) $attrs['text'] : $defaultText;
        unset($attrs['text']);

        $class = $markerClass;
        if (isset($attrs['class']) && is_scalar($attrs['class'])) {
            $extra = trim((string) $attrs['class']);
            if ($extra !== '') {
                $class .= ' ' . $extra;
            }
        }
        unset($attrs['class']);

        $parts = [sprintf('class="%s"', self::escape($class))];

        foreach ($dataAttrs as $name => $value) {
            $parts[] = sprintf('%s="%s"', $name, self::escape($value));
        }

        /** @var mixed $value */
        foreach ($attrs as $name => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            // href is reassembled by the script; never let a caller inject one.
            $safeName = ltrim(self::escape((string) $name), '/');
            if ($safeName === '' || $safeName === 'href') {
                continue;
            }

            $parts[] = sprintf('%s="%s"', $safeName, self::escape((string) $value));
        }

        return sprintf('<a %s>%s</a>', implode(' ', $parts), self::escape($text));
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
