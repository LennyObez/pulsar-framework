<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Override;
use Pulsar\Api\Internal;

/**
 * Video player directive: renders a branded HTML5 video player.
 *
 * Usage: {@}video($mediaId) or {@}video($mediaId, ['autoplay' => false])
 *
 * Compiles to a PHP block that resolves the media asset by ID and renders
 * the Pulsar branded video player with HLS support, keyboard accessibility,
 * and quality/speed controls.
 *
 * Requires video-player.js and video-player.css to be loaded on the page.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class VideoDirective implements DirectiveInterface
{
    #[Override]
    public function name(): string
    {
        return 'video';
    }

    #[Override]
    public function compile(string $expression): string
    {
        return <<<PHP
            <?php
            \$__videoArgs = [{$expression}];
            \$__videoId = \$__videoArgs[0] ?? null;
            \$__videoOpts = \$__videoArgs[1] ?? [];
            \$__videoAutoplay = \$__videoOpts['autoplay'] ?? false;
            \$__videoLoop = \$__videoOpts['loop'] ?? false;
            \$__videoMuted = \$__videoOpts['muted'] ?? false;
            \$__videoPoster = \$__videoOpts['poster'] ?? null;
            \$__videoSrc = \$__videoOpts['src'] ?? null;
            \$__videoHls = \$__videoOpts['hls'] ?? null;
            \$__videoTitle = \$__videoOpts['title'] ?? 'Video player';
            \$__videoWidth = \$__videoOpts['width'] ?? null;
            \$__videoHeight = \$__videoOpts['height'] ?? null;

            if (\$__videoId !== null && \$__videoSrc === null && isset(\$__mediaService)) {
                \$__videoAsset = \$__mediaService->find(\$__videoId);
                if (\$__videoAsset !== null) {
                    \$__videoSrc = \$__videoAsset->url;
                    \$__videoPoster = \$__videoPoster ?? \$__videoAsset->thumbnailUrl ?? null;
                    \$__videoHls = \$__videoHls ?? \$__videoAsset->hlsPlaylistUrl ?? null;
                    \$__videoTitle = \$__videoTitle !== 'Video player' ? \$__videoTitle : (\$__videoAsset->alt ?? \$__videoAsset->title ?? 'Video player');
                }
            }
            ?>
            <div class="pui-video-player" data-pui-video<?= \$__videoHls ? ' data-hls-src="' . htmlspecialchars(\$__videoHls, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' : '' ?><?= \$__videoWidth ? ' style="max-width:' . (int)\$__videoWidth . 'px"' : '' ?> role="region" aria-label="<?= htmlspecialchars(\$__videoTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <video
                class="pui-video-player__video"
                <?= \$__videoSrc ? 'src="' . htmlspecialchars(\$__videoSrc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' : '' ?>
                <?= \$__videoPoster ? 'poster="' . htmlspecialchars(\$__videoPoster, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' : '' ?>
                <?= \$__videoAutoplay ? 'autoplay' : '' ?>
                <?= \$__videoLoop ? 'loop' : '' ?>
                <?= \$__videoMuted ? 'muted' : '' ?>
                <?= \$__videoWidth ? 'width="' . (int)\$__videoWidth . '"' : '' ?>
                <?= \$__videoHeight ? 'height="' . (int)\$__videoHeight . '"' : '' ?>
                playsinline
                preload="metadata"
                crossorigin="anonymous"
              >Your browser does not support HTML5 video.</video>
              <div class="pui-video-player__controls" role="toolbar" aria-label="Video controls">
                <button type="button" class="pui-video-player__btn" data-action="play" aria-label="Play"><span aria-hidden="true">&#9654;</span></button>
                <div class="pui-video-player__time"><span data-time="current">0:00</span> / <span data-time="duration">0:00</span></div>
                <input type="range" class="pui-video-player__seek" min="0" max="100" value="0" step="0.1" aria-label="Seek" />
                <button type="button" class="pui-video-player__btn" data-action="mute" aria-label="Mute"><span aria-hidden="true">&#128266;</span></button>
                <input type="range" class="pui-video-player__volume" min="0" max="1" value="1" step="0.05" aria-label="Volume" />
                <select class="pui-video-player__speed" aria-label="Playback speed" data-action="speed">
                  <option value="0.5">0.5x</option>
                  <option value="0.75">0.75x</option>
                  <option value="1" selected>1x</option>
                  <option value="1.25">1.25x</option>
                  <option value="1.5">1.5x</option>
                  <option value="2">2x</option>
                </select>
                <select class="pui-video-player__quality" aria-label="Quality" data-action="quality" hidden></select>
                <button type="button" class="pui-video-player__btn" data-action="fullscreen" aria-label="Fullscreen"><span aria-hidden="true">&#x26F6;</span></button>
              </div>
            </div>
            <?php unset(\$__videoArgs, \$__videoId, \$__videoOpts, \$__videoAutoplay, \$__videoLoop, \$__videoMuted, \$__videoPoster, \$__videoSrc, \$__videoHls, \$__videoTitle, \$__videoWidth, \$__videoHeight, \$__videoAsset); ?>
            PHP;
    }
}
