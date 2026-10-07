<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Transparency;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * The kinds of output Article 50(2) requires to be marked.
 *
 * The Article names "synthetic audio, image, video or text content". The cases
 * below are those four and nothing else, because the obligation attaches to what
 * the output IS rather than to how a deployment happens to categorise it.
 *
 * The distinction the cases carry is not cosmetic. Article 50(4) adds a further
 * DEPLOYER duty for image, audio and video that constitutes a deep fake, and no
 * equivalent duty for text unless it is published to inform the public on matters
 * of public interest. A marking layer that flattened these into one "content"
 * case could not tell the two duties apart.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
enum SyntheticContentKind: string
{
    case Text = 'text';
    case Image = 'image';
    case Audio = 'audio';
    case Video = 'video';

    /**
     * Whether Article 50(4)'s deep-fake disclosure can attach to this kind.
     *
     * Article 50(4) first subparagraph binds deployers who generate or manipulate
     * "image, audio or video content constituting a deep fake". Text is governed
     * by the second subparagraph on different terms, so it answers false here and
     * is not thereby exempt from anything.
     */
    #[NoDiscard]
    public function canConstituteDeepFake(): bool
    {
        return match ($this) {
            self::Image, self::Audio, self::Video => true,
            self::Text => false,
        };
    }
}
