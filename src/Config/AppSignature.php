<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function htmlspecialchars;
use function implode;
use function is_string;
use function trim;

use const ENT_HTML5;
use const ENT_QUOTES;

/**
 * Opt-in "Made with Pulsar" front-end signal, rendered as `<meta>` tags in the
 * `<head>` of framework-rendered pages.
 *
 * This is deliberately a *front-end* signal — a `<meta>` tag anyone can read in
 * the page source — and NOT an HTTP response header. A response header such as
 * `X-Powered-By` advertises the framework (and usually its version) to every
 * client, including attackers, and is a fingerprinting leak (OWASP ASVS
 * V14.4.1); Pulsar strips those at the emitter. A `<meta name="generator">`
 * carries no version and is disabled by default, so nothing is disclosed unless
 * the operator opts in.
 *
 * Maps from the `signature` key of `config/app.php` and is exposed to every
 * render as the `$pulsarSignature` view variable (empty string when disabled).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AppSignature
{
    /**
     * @param bool $generator Emit `<meta name="generator" content="Pulsar">`.
     * @param string $author Site owner; emitted as `<meta name="author">` when
     *     non-empty (blank = omitted).
     */
    public function __construct(
        public bool $generator = false,
        public string $author = '',
    ) {}

    /**
     * Build from the raw `signature` sub-array of `config/app.php`.
     *
     * @param array{generator?: bool|int|string, author?: bool|int|string} $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var mixed $author */
        $author = $data['author'] ?? '';

        return new self(
            generator: (bool) ($data['generator'] ?? false),
            author: is_string($author) ? trim($author) : '',
        );
    }

    /**
     * Render the enabled signature meta tags as an HTML fragment (empty string
     * when nothing is enabled).
     *
     * The generator tag intentionally carries no version — the goal is to signal
     * the framework, never to hand an attacker a version to match CVEs against.
     * The author value is operator-supplied, so it is HTML-escaped before it is
     * placed into the attribute.
     */
    #[NoDiscard]
    public function toHtml(): string
    {
        $lines = [];

        if ($this->generator) {
            $lines[] = '<meta name="generator" content="Pulsar">';
        }

        if ($this->author !== '') {
            $lines[] = '<meta name="author" content="'
                . htmlspecialchars($this->author, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                . '">';
        }

        return implode("\n", $lines);
    }
}
