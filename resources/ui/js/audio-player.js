/**
 * Pulsar UI — Audio Player v1.0.0
 *
 * Branded HTML5 audio player with waveform visualization,
 * progress overlay, playback speed, and keyboard navigation.
 * Zero external dependencies.
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
   * Draw waveform bars on a canvas from amplitude data.
   *
   * @param {HTMLCanvasElement} canvas
   * @param {number[]} amplitudes Normalized 0.0-1.0 values
   * @param {number} progress Current playback progress 0.0-1.0
   */
  function drawWaveform(canvas, amplitudes, progress) {
    var ctx = canvas.getContext('2d');
    if (!ctx) return;

    var dpr = window.devicePixelRatio || 1;
    var rect = canvas.getBoundingClientRect();
    canvas.width = rect.width * dpr;
    canvas.height = rect.height * dpr;
    ctx.scale(dpr, dpr);

    var w = rect.width;
    var h = rect.height;
    var barCount = amplitudes.length;
    var barWidth = Math.max(1, (w / barCount) * 0.7);
    var gap = (w / barCount) * 0.3;
    var minBarHeight = 2;

    ctx.clearRect(0, 0, w, h);

    // Compute CSS variable values for colors
    var style = getComputedStyle(canvas);
    var primaryColor = style.getPropertyValue('--color-primary-500').trim() || '#0039CB';
    var mutedColor = style.getPropertyValue('--color-gray-400').trim() || '#94a3b8';

    for (var i = 0; i < barCount; i++) {
      var x = i * (barWidth + gap);
      var amplitude = amplitudes[i] || 0;
      var barHeight = Math.max(minBarHeight, amplitude * h * 0.85);
      var y = (h - barHeight) / 2;

      var fraction = x / w;
      ctx.fillStyle = fraction <= progress ? primaryColor : mutedColor;
      ctx.fillRect(x, y, barWidth, barHeight);
    }
  }

  function initAudioPlayer(container) {
    var audio = container.querySelector('.pui-audio-player__audio');
    if (!audio) return;

    var playBtn = container.querySelector('[data-action="play"]');
    var muteBtn = container.querySelector('[data-action="mute"]');
    var seekBar = container.querySelector('.pui-audio-player__seek');
    var volumeBar = container.querySelector('.pui-audio-player__volume');
    var speedSelect = container.querySelector('[data-action="speed"]');
    var currentTimeEl = container.querySelector('[data-time="current"]');
    var durationEl = container.querySelector('[data-time="duration"]');
    var waveformCanvas = container.querySelector('.pui-audio-player__waveform');
    var progressOverlay = container.querySelector('.pui-audio-player__progress-overlay');

    var waveformData = null;

    // Parse waveform data from data attribute
    var rawWaveform = container.dataset.waveform;
    if (rawWaveform) {
      try {
        waveformData = JSON.parse(rawWaveform);
      } catch (e) {
        // Invalid JSON, generate flat waveform
        waveformData = null;
      }
    }

    // Default waveform if none provided
    if (!waveformData || !Array.isArray(waveformData)) {
      waveformData = [];
      for (var i = 0; i < 128; i++) {
        waveformData.push(0.3 + Math.random() * 0.4);
      }
    }

    // Draw initial waveform
    if (waveformCanvas) {
      drawWaveform(waveformCanvas, waveformData, 0);
    }

    // Play/Pause
    function updatePlayButton() {
      if (!playBtn) return;
      if (audio.paused) {
        playBtn.querySelector('span').textContent = PLAY_ICON;
        playBtn.setAttribute('aria-label', 'Play');
      } else {
        playBtn.querySelector('span').textContent = PAUSE_ICON;
        playBtn.setAttribute('aria-label', 'Pause');
      }
    }

    function togglePlay() {
      if (audio.paused) {
        audio.play();
      } else {
        audio.pause();
      }
    }

    if (playBtn) {
      playBtn.addEventListener('click', togglePlay);
    }

    audio.addEventListener('play', updatePlayButton);
    audio.addEventListener('pause', updatePlayButton);

    // Time updates
    audio.addEventListener('timeupdate', function () {
      if (currentTimeEl) currentTimeEl.textContent = formatTime(audio.currentTime);

      var progress = audio.duration ? audio.currentTime / audio.duration : 0;

      if (seekBar && !seekBar._dragging) {
        seekBar.value = progress * 100;
      }

      // Update progress overlay
      if (progressOverlay) {
        progressOverlay.style.width = progress * 100 + '%';
      }

      // Redraw waveform with progress
      if (waveformCanvas && waveformData) {
        drawWaveform(waveformCanvas, waveformData, progress);
      }
    });

    audio.addEventListener('loadedmetadata', function () {
      if (durationEl) durationEl.textContent = formatTime(audio.duration);
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
        if (audio.duration) {
          audio.currentTime = (seekBar.value / 100) * audio.duration;
        }
      });
    }

    // Volume
    if (volumeBar) {
      volumeBar.addEventListener('input', function () {
        audio.volume = parseFloat(volumeBar.value);
        audio.muted = audio.volume === 0;
        updateMuteButton();
      });
    }

    // Mute
    function updateMuteButton() {
      if (!muteBtn) return;
      muteBtn.querySelector('span').textContent = audio.muted ? MUTE_ICON : VOLUME_ICON;
      muteBtn.setAttribute('aria-label', audio.muted ? 'Unmute' : 'Mute');
    }

    if (muteBtn) {
      muteBtn.addEventListener('click', function () {
        audio.muted = !audio.muted;
        updateMuteButton();
        if (volumeBar) volumeBar.value = audio.muted ? 0 : audio.volume;
      });
    }

    // Speed
    if (speedSelect) {
      speedSelect.addEventListener('change', function () {
        audio.playbackRate = parseFloat(speedSelect.value);
      });
    }

    // Resize waveform on window resize
    var resizeTimer = null;
    window.addEventListener('resize', function () {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function () {
        if (waveformCanvas && waveformData) {
          var progress = audio.duration ? audio.currentTime / audio.duration : 0;
          drawWaveform(waveformCanvas, waveformData, progress);
        }
      }, 150);
    });

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
          audio.currentTime = Math.min(audio.currentTime + 5, audio.duration || 0);
          break;
        case 'ArrowLeft':
          e.preventDefault();
          audio.currentTime = Math.max(audio.currentTime - 5, 0);
          break;
        case 'ArrowUp':
          e.preventDefault();
          audio.volume = Math.min(audio.volume + 0.1, 1);
          if (volumeBar) volumeBar.value = audio.volume;
          break;
        case 'ArrowDown':
          e.preventDefault();
          audio.volume = Math.max(audio.volume - 0.1, 0);
          if (volumeBar) volumeBar.value = audio.volume;
          break;
        case 'm':
          e.preventDefault();
          audio.muted = !audio.muted;
          updateMuteButton();
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
    document.querySelectorAll('[data-pui-audio]').forEach(initAudioPlayer);
  });

  window.PulsarAudioPlayer = { init: initAudioPlayer, drawWaveform: drawWaveform };
})();
