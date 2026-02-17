/**
 * Pulsar UI — Video Player v1.0.0
 *
 * Branded HTML5 video player with HLS.js adaptive streaming,
 * quality selector, playback speed, and keyboard navigation.
 * Zero dependencies beyond optional HLS.js for adaptive streaming.
 *
 * HLS.js must be loaded before this script for HLS playback:
 *   <script src="https://cdn.jsdelivr.net/npm/hls.js@1/dist/hls.min.js"></script>
 */
'use strict';

(function () {
  var PLAY_ICON = '\u25B6';
  var PAUSE_ICON = '\u23F8';
  var VOLUME_ICON = '\uD83D\uDD0A';
  var MUTE_ICON = '\uD83D\uDD07';

  function formatTime(seconds) {
    if (isNaN(seconds) || !isFinite(seconds)) return '0:00';
    var s = Math.floor(seconds);
    var h = Math.floor(s / 3600);
    var m = Math.floor((s % 3600) / 60);
    var sec = s % 60;
    if (h > 0) {
      return h + ':' + (m < 10 ? '0' : '') + m + ':' + (sec < 10 ? '0' : '') + sec;
    }
    return m + ':' + (sec < 10 ? '0' : '') + sec;
  }

  /**
   * Create an <option> element safely without innerHTML.
   */
  function createOption(value, label, selected) {
    var opt = document.createElement('option');
    opt.value = String(value);
    opt.textContent = label;
    if (selected) opt.selected = true;
    return opt;
  }

  function initVideoPlayer(container) {
    var video = container.querySelector('.pui-video-player__video');
    if (!video) return;

    var playBtn = container.querySelector('[data-action="play"]');
    var muteBtn = container.querySelector('[data-action="mute"]');
    var fullscreenBtn = container.querySelector('[data-action="fullscreen"]');
    var seekBar = container.querySelector('.pui-video-player__seek');
    var volumeBar = container.querySelector('.pui-video-player__volume');
    var speedSelect = container.querySelector('[data-action="speed"]');
    var qualitySelect = container.querySelector('[data-action="quality"]');
    var currentTimeEl = container.querySelector('[data-time="current"]');
    var durationEl = container.querySelector('[data-time="duration"]');

    var hlsSrc = container.dataset.hlsSrc || null;
    var hls = null;

    // HLS initialization
    if (hlsSrc && typeof Hls !== 'undefined' && Hls.isSupported()) {
      hls = new Hls({ enableWorker: true, lowLatencyMode: false });
      hls.loadSource(hlsSrc);
      hls.attachMedia(video);

      hls.on(Hls.Events.MANIFEST_PARSED, function (event, data) {
        if (qualitySelect && data.levels.length > 1) {
          qualitySelect.hidden = false;
          // Clear existing options safely
          while (qualitySelect.firstChild) {
            qualitySelect.removeChild(qualitySelect.firstChild);
          }
          qualitySelect.appendChild(createOption('-1', 'Auto', true));
          for (var i = 0; i < data.levels.length; i++) {
            var level = data.levels[i];
            var label = level.height + 'p';
            qualitySelect.appendChild(createOption(String(i), label, false));
          }
        }
      });
    } else if (hlsSrc && video.canPlayType('application/vnd.apple.mpegurl')) {
      // Native HLS (Safari)
      video.src = hlsSrc;
    }

    // Quality switching
    if (qualitySelect) {
      qualitySelect.addEventListener('change', function () {
        if (hls) {
          hls.currentLevel = parseInt(qualitySelect.value, 10);
        }
      });
    }

    // Play/Pause
    function updatePlayButton() {
      if (video.paused) {
        playBtn.querySelector('span').textContent = PLAY_ICON;
        playBtn.setAttribute('aria-label', 'Play');
        container.classList.remove('pui-video-player--playing');
      } else {
        playBtn.querySelector('span').textContent = PAUSE_ICON;
        playBtn.setAttribute('aria-label', 'Pause');
        container.classList.add('pui-video-player--playing');
      }
    }

    function togglePlay() {
      if (video.paused) {
        video.play();
      } else {
        video.pause();
      }
    }

    if (playBtn) {
      playBtn.addEventListener('click', togglePlay);
    }

    video.addEventListener('click', togglePlay);
    video.addEventListener('play', updatePlayButton);
    video.addEventListener('pause', updatePlayButton);

    // Time updates
    video.addEventListener('timeupdate', function () {
      if (currentTimeEl) currentTimeEl.textContent = formatTime(video.currentTime);
      if (seekBar && !seekBar._dragging) {
        seekBar.value = video.duration ? (video.currentTime / video.duration) * 100 : 0;
      }
    });

    video.addEventListener('loadedmetadata', function () {
      if (durationEl) durationEl.textContent = formatTime(video.duration);
      if (seekBar) seekBar.max = 100;
    });

    // Seek
    if (seekBar) {
      seekBar.addEventListener('mousedown', function () {
        seekBar._dragging = true;
      });
      seekBar.addEventListener('mouseup', function () {
        seekBar._dragging = false;
      });
      seekBar.addEventListener('input', function () {
        if (video.duration) {
          video.currentTime = (seekBar.value / 100) * video.duration;
        }
      });
    }

    // Volume
    if (volumeBar) {
      volumeBar.addEventListener('input', function () {
        video.volume = parseFloat(volumeBar.value);
        video.muted = video.volume === 0;
        updateMuteButton();
      });
    }

    // Mute
    function updateMuteButton() {
      if (!muteBtn) return;
      muteBtn.querySelector('span').textContent = video.muted ? MUTE_ICON : VOLUME_ICON;
      muteBtn.setAttribute('aria-label', video.muted ? 'Unmute' : 'Mute');
    }

    if (muteBtn) {
      muteBtn.addEventListener('click', function () {
        video.muted = !video.muted;
        updateMuteButton();
        if (volumeBar) volumeBar.value = video.muted ? 0 : video.volume;
      });
    }

    // Speed
    if (speedSelect) {
      speedSelect.addEventListener('change', function () {
        video.playbackRate = parseFloat(speedSelect.value);
      });
    }

    // Fullscreen
    if (fullscreenBtn) {
      fullscreenBtn.addEventListener('click', function () {
        if (document.fullscreenElement === container) {
          document.exitFullscreen();
        } else {
          container.requestFullscreen();
        }
      });
    }

    // Keyboard shortcuts
    container.addEventListener('keydown', function (e) {
      switch (e.key) {
        case ' ':
        case 'k':
          e.preventDefault();
          togglePlay();
          break;
        case 'ArrowRight':
          e.preventDefault();
          video.currentTime = Math.min(video.currentTime + 5, video.duration || 0);
          break;
        case 'ArrowLeft':
          e.preventDefault();
          video.currentTime = Math.max(video.currentTime - 5, 0);
          break;
        case 'ArrowUp':
          e.preventDefault();
          video.volume = Math.min(video.volume + 0.1, 1);
          if (volumeBar) volumeBar.value = video.volume;
          break;
        case 'ArrowDown':
          e.preventDefault();
          video.volume = Math.max(video.volume - 0.1, 0);
          if (volumeBar) volumeBar.value = video.volume;
          break;
        case 'm':
          e.preventDefault();
          video.muted = !video.muted;
          updateMuteButton();
          break;
        case 'f':
          e.preventDefault();
          if (document.fullscreenElement === container) {
            document.exitFullscreen();
          } else {
            container.requestFullscreen();
          }
          break;
      }
    });

    // Make container focusable for keyboard events
    if (!container.hasAttribute('tabindex')) {
      container.setAttribute('tabindex', '0');
    }
  }

  // Auto-initialize
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-pui-video]').forEach(initVideoPlayer);
  });

  window.PulsarVideoPlayer = { init: initVideoPlayer };
})();
