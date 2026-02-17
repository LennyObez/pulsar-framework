<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Override;
use Pulsar\Api\Internal;

/**
 * Audio player directive: renders a branded HTML5 audio player with waveform.
 *
 * Usage: {@}audio($mediaId) or {@}audio($mediaId, ['waveform' => true])
 *
 * Compiles to a PHP block that resolves the media asset by ID and renders
 * the Pulsar branded audio player with waveform visualization, progress
 * overlay, and playback speed controls.
 *
 * Requires audio-player.js and audio-player.css to be loaded on the page.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class AudioDirective implements DirectiveInterface
{
    #[Override]
    public function name(): string
    {
        return 'audio';
    }

    #[Override]
    public function compile(string $expression): string
    {
        return <<<PHP
            <?php
            \$__audioArgs = [{$expression}];
            \$__audioId = \$__audioArgs[0] ?? null;
            \$__audioOpts = \$__audioArgs[1] ?? [];
            \$__audioAutoplay = \$__audioOpts['autoplay'] ?? false;
            \$__audioLoop = \$__audioOpts['loop'] ?? false;
            \$__audioSrc = \$__audioOpts['src'] ?? null;
            \$__audioTitle = \$__audioOpts['title'] ?? 'Audio player';
            \$__audioArtist = \$__audioOpts['artist'] ?? null;
            \$__audioWaveform = \$__audioOpts['waveform'] ?? null;

            if (\$__audioId !== null && \$__audioSrc === null && isset(\$__mediaService)) {
                \$__audioAsset = \$__mediaService->find(\$__audioId);
                if (\$__audioAsset !== null) {
                    \$__audioSrc = \$__audioAsset->url;
                    \$__audioTitle = \$__audioTitle !== 'Audio player' ? \$__audioTitle : (\$__audioAsset->title ?? 'Audio player');
                }
            }
            ?>
            <div class="pui-audio-player" data-pui-audio<?= \$__audioWaveform ? ' data-waveform="' . htmlspecialchars(\$__audioWaveform, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' : '' ?> role="region" aria-label="<?= htmlspecialchars(\$__audioTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <audio
                class="pui-audio-player__audio"
                <?= \$__audioSrc ? 'src="' . htmlspecialchars(\$__audioSrc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' : '' ?>
                <?= \$__audioAutoplay ? 'autoplay' : '' ?>
                <?= \$__audioLoop ? 'loop' : '' ?>
                preload="metadata"
                crossorigin="anonymous"
              >Your browser does not support HTML5 audio.</audio>
              <div class="pui-audio-player__info">
                <span class="pui-audio-player__title"><?= htmlspecialchars(\$__audioTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <?php if (\$__audioArtist): ?>
                  <span class="pui-audio-player__artist"><?= htmlspecialchars(\$__audioArtist, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <?php endif; ?>
              </div>
              <div class="pui-audio-player__waveform-container">
                <canvas class="pui-audio-player__waveform" aria-hidden="true"></canvas>
                <div class="pui-audio-player__progress-overlay"></div>
                <input type="range" class="pui-audio-player__seek" min="0" max="100" value="0" step="0.1" aria-label="Seek" />
              </div>
              <div class="pui-audio-player__controls" role="toolbar" aria-label="Audio controls">
                <button type="button" class="pui-audio-player__btn" data-action="play" aria-label="Play"><span aria-hidden="true">&#9654;</span></button>
                <div class="pui-audio-player__time"><span data-time="current">0:00</span> / <span data-time="duration">0:00</span></div>
                <button type="button" class="pui-audio-player__btn" data-action="mute" aria-label="Mute"><span aria-hidden="true">&#128266;</span></button>
                <input type="range" class="pui-audio-player__volume" min="0" max="1" value="1" step="0.05" aria-label="Volume" />
                <select class="pui-audio-player__speed" aria-label="Playback speed" data-action="speed">
                  <option value="0.5">0.5x</option>
                  <option value="0.75">0.75x</option>
                  <option value="1" selected>1x</option>
                  <option value="1.25">1.25x</option>
                  <option value="1.5">1.5x</option>
                  <option value="2">2x</option>
                </select>
              </div>
            </div>
            <?php unset(\$__audioArgs, \$__audioId, \$__audioOpts, \$__audioAutoplay, \$__audioLoop, \$__audioSrc, \$__audioTitle, \$__audioArtist, \$__audioWaveform, \$__audioAsset); ?>
            PHP;
    }
}
