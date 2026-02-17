<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\Variation;

use Pulsar\Api\Api;

/**
 * Built-in block variations for core block types.
 */
#[Api(since: '1.0.0')]
final class CoreVariations
{
    /**
     * Register all core variations with the given registry.
     */
    public static function register(VariationRegistry $registry): void
    {
        // Embed block variations
        $registry->register(new BlockVariation(
            name: 'youtube',
            blockType: 'embed',
            title: 'YouTube',
            description: 'Embed a YouTube video',
            defaults: ['type' => 'youtube'],
            icon: 'youtube',
        ));

        $registry->register(new BlockVariation(
            name: 'vimeo',
            blockType: 'embed',
            title: 'Vimeo',
            description: 'Embed a Vimeo video',
            defaults: ['type' => 'vimeo'],
            icon: 'vimeo',
        ));

        $registry->register(new BlockVariation(
            name: 'twitter',
            blockType: 'embed',
            title: 'Twitter / X',
            description: 'Embed a tweet',
            defaults: ['type' => 'twitter'],
            icon: 'twitter',
        ));

        $registry->register(new BlockVariation(
            name: 'codepen',
            blockType: 'embed',
            title: 'CodePen',
            description: 'Embed a CodePen pen',
            defaults: ['type' => 'codepen'],
            icon: 'codepen',
        ));

        $registry->register(new BlockVariation(
            name: 'spotify',
            blockType: 'embed',
            title: 'Spotify',
            description: 'Embed a Spotify track or playlist',
            defaults: ['type' => 'spotify'],
            icon: 'spotify',
        ));
    }
}
