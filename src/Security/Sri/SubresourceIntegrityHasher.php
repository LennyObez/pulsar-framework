<?php

declare(strict_types=1);

namespace Pulsar\Security\Sri;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function base64_encode;
use function file_get_contents;
use function hash;
use function sprintf;

/**
 * Generates Subresource Integrity (SRI) hashes for CSS and JS assets.
 *
 * SRI hashes allow browsers to verify that fetched resources have not been
 * tampered with (CWE-829: Inclusion of Functionality from Untrusted Control Sphere).
 *
 * Usage in templates:
 *
 *     <script src="/js/app.js" integrity="<?= $sri->hashFile('/path/to/app.js') ?>" crossorigin="anonymous"></script>
 *     <link rel="stylesheet" href="/css/app.css" integrity="<?= $sri->hashFile('/path/to/app.css') ?>" crossorigin="anonymous">
 *
 * The crossorigin="anonymous" attribute is required when loading resources
 * from a CDN or different origin with SRI enabled.
 *
 * @see https://www.w3.org/TR/SRI/
 * @see https://developer.mozilla.org/en-US/docs/Web/Security/Subresource_Integrity
 */
#[Api(since: '1.0.0')]
final readonly class SubresourceIntegrityHasher
{
    public function __construct(
        private SriAlgorithm $algorithm = SriAlgorithm::Sha384,
    ) {}

    /**
     * Hash raw content and return the SRI integrity value.
     *
     * Returns a string in the format "algorithm-base64hash" suitable for
     * use in the integrity attribute of <script> and <link> elements.
     */
    #[NoDiscard]
    public function hash(string $content): string
    {
        $rawHash = hash($this->algorithm->value, $content, binary: true);

        return sprintf('%s-%s', $this->algorithm->value, base64_encode($rawHash));
    }

    /**
     * Hash a file and return the SRI integrity value.
     *
     * @throws RuntimeException If the file cannot be read
     */
    #[NoDiscard]
    public function hashFile(string $path): string
    {
        $content = @file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException(
                sprintf('Cannot read file for SRI hash: %s', $path),
            );
        }

        return $this->hash($content);
    }

    /**
     * Generate a full integrity attribute value with multiple algorithms.
     *
     * Browsers use the strongest algorithm present. Including multiple
     * algorithms provides forward compatibility as browsers add support.
     *
     * @param list<SriAlgorithm> $algorithms Algorithms to include (strongest first)
     */
    #[NoDiscard]
    public static function multiHash(string $content, array $algorithms): string
    {
        $parts = [];

        foreach ($algorithms as $algo) {
            $rawHash = hash($algo->value, $content, binary: true);
            $parts[] = sprintf('%s-%s', $algo->value, base64_encode($rawHash));
        }

        return implode(' ', $parts);
    }
}
