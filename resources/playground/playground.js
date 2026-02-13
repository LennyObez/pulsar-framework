/**
 * Pulsar UI Playground — Theme Management & Preview
 *
 * Handles:
 * - Fetching/saving/deleting themes via the API
 * - Loading theme CSS into the editor
 * - Injecting CSS into the catalog iframe in real time
 * - Responsive preview toggles (desktop/tablet/mobile)
 * - Split pane drag resizing
 * - CSRF token management
 *
 * Zero external dependencies.
 */
'use strict';

(function () {
  // -----------------------------------------------------------------------
  // State
  // -----------------------------------------------------------------------
  var csrfToken = '';
  var currentThemeName = 'default';
  var isDirty = false;

  // -----------------------------------------------------------------------
  // DOM References (populated on DOMContentLoaded)
  // -----------------------------------------------------------------------
  /** @type {HTMLIFrameElement} */
  var iframe;
  /** @type {HTMLSelectElement} */
  var themeSelect;
  /** @type {HTMLElement} */
  var editorPane;
  /** @type {HTMLElement} */
  var previewWrapper;
  /** @type {HTMLElement} */
  var handle;
  /** @type {HTMLElement} */
  var toast;
  /** @type {HTMLElement} */
  var dialogBackdrop;
  /** @type {HTMLInputElement} */
  var dialogInput;
  /** @type {HTMLElement} */
  var statusDot;
  /** @type {HTMLElement} */
  var statusText;

  // -----------------------------------------------------------------------
  // API Helpers
  // -----------------------------------------------------------------------

  /**
   * Fetch the CSRF token from the server.
   * @returns {Promise<string>}
   */
  function fetchCsrfToken() {
    return fetch('/api/csrf-token')
      .then(function (res) {
        return res.json();
      })
      .then(function (data) {
        csrfToken = data.token;
        return csrfToken;
      });
  }

  /**
   * Fetch the list of available themes.
   * @returns {Promise<string[]>}
   */
  function fetchThemes() {
    return fetch('/api/themes').then(function (res) {
      return res.json();
    });
  }

  /**
   * Load a theme's CSS content from the API.
   * @param {string} name
   * @returns {Promise<string>}
   */
  function loadThemeCSS(name) {
    return fetch('/api/themes/' + encodeURIComponent(name))
      .then(function (res) {
        if (!res.ok) throw new Error('Theme not found');
        return res.json();
      })
      .then(function (data) {
        return data.css;
      });
  }

  /**
   * Save theme CSS to the server.
   * @param {string} name
   * @param {string} css
   * @returns {Promise<Object>}
   */
  function saveTheme(name, css) {
    return fetch('/api/themes/' + encodeURIComponent(name), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
      },
      body: JSON.stringify({ css: css }),
    }).then(function (res) {
      if (!res.ok) {
        return res.json().then(function (data) {
          throw new Error(data.error || 'Save failed');
        });
      }
      return res.json();
    });
  }

  /**
   * Delete a custom theme from the server.
   * @param {string} name
   * @returns {Promise<Object>}
   */
  function deleteTheme(name) {
    return fetch('/api/themes/' + encodeURIComponent(name), {
      method: 'DELETE',
      headers: {
        'X-CSRF-TOKEN': csrfToken,
      },
    }).then(function (res) {
      if (!res.ok) {
        return res.json().then(function (data) {
          throw new Error(data.error || 'Delete failed');
        });
      }
      return res.json();
    });
  }

  // -----------------------------------------------------------------------
  // Theme Selector
  // -----------------------------------------------------------------------

  /**
   * Populate the theme dropdown with available themes.
   */
  function refreshThemeList() {
    return fetchThemes().then(function (themes) {
      var currentValue = themeSelect.value;
      themeSelect.innerHTML = '';

      themes.forEach(function (name) {
        var opt = document.createElement('option');
        opt.value = name;
        opt.textContent = name;
        if (name === 'default') {
          opt.textContent = 'default (read-only)';
        }
        themeSelect.appendChild(opt);
      });

      // Restore selection if still valid
      if (themes.indexOf(currentValue) !== -1) {
        themeSelect.value = currentValue;
      } else {
        themeSelect.value = themes[0] || 'default';
      }
    });
  }

  /**
   * Switch to a different theme.
   * @param {string} name
   */
  function switchTheme(name) {
    loadThemeCSS(name)
      .then(function (css) {
        PulsarEditor.setValue(css);
        currentThemeName = name;
        isDirty = false;
        injectCSS(css);
        updateStatus();
      })
      .catch(function () {
        showToast('Failed to load theme: ' + name, true);
      });
  }

  // -----------------------------------------------------------------------
  // CSS Injection
  // -----------------------------------------------------------------------

  /**
   * Inject CSS into the catalog iframe.
   * @param {string} css
   */
  function injectCSS(css) {
    if (!iframe || !iframe.contentDocument) return;
    var style = iframe.contentDocument.getElementById('custom-theme');
    if (style) {
      style.textContent = css;
    }
  }

  // -----------------------------------------------------------------------
  // Save Flow
  // -----------------------------------------------------------------------

  /**
   * Handle the save action.
   */
  function handleSave() {
    var css = PulsarEditor.getValue();

    // If current theme is default or we have no custom name, prompt for name
    if (currentThemeName === 'default') {
      openSaveDialog();
      return;
    }

    saveTheme(currentThemeName, css)
      .then(function () {
        isDirty = false;
        updateStatus();
        showToast('Theme "' + currentThemeName + '" saved');
        refreshThemeList();
      })
      .catch(function (err) {
        showToast(err.message, true);
      });
  }

  /**
   * Open the "save as" dialog.
   */
  function openSaveDialog() {
    dialogBackdrop.classList.add('pg-dialog-backdrop--open');
    dialogInput.value = currentThemeName === 'default' ? '' : currentThemeName;
    dialogInput.focus();
    dialogInput.select();
  }

  /**
   * Close the save dialog.
   */
  function closeSaveDialog() {
    dialogBackdrop.classList.remove('pg-dialog-backdrop--open');
  }

  /**
   * Confirm save from dialog.
   */
  function confirmSaveDialog() {
    var name = dialogInput.value.trim();

    if (!name) {
      dialogInput.focus();
      return;
    }

    if (!/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?$/.test(name)) {
      showToast('Invalid name. Use letters, numbers, and hyphens.', true);
      dialogInput.focus();
      return;
    }

    if (name === 'default') {
      showToast('Cannot overwrite the default theme.', true);
      dialogInput.focus();
      return;
    }

    var css = PulsarEditor.getValue();

    closeSaveDialog();

    saveTheme(name, css)
      .then(function () {
        currentThemeName = name;
        isDirty = false;
        updateStatus();
        showToast('Theme "' + name + '" saved');
        return refreshThemeList();
      })
      .then(function () {
        themeSelect.value = name;
      })
      .catch(function (err) {
        showToast(err.message, true);
      });
  }

  // -----------------------------------------------------------------------
  // Reset
  // -----------------------------------------------------------------------

  /**
   * Reset to the default theme.
   */
  function handleReset() {
    themeSelect.value = 'default';
    switchTheme('default');
  }

  // -----------------------------------------------------------------------
  // Responsive Preview
  // -----------------------------------------------------------------------

  /**
   * Set the preview mode.
   * @param {'desktop'|'tablet'|'mobile'} mode
   */
  function setPreviewMode(mode) {
    iframe.classList.remove('pg-preview-frame--tablet', 'pg-preview-frame--mobile');

    if (mode === 'tablet') {
      iframe.classList.add('pg-preview-frame--tablet');
    } else if (mode === 'mobile') {
      iframe.classList.add('pg-preview-frame--mobile');
    }

    // Update active state on buttons
    document.querySelectorAll('[data-preview]').forEach(function (btn) {
      btn.classList.toggle('pg-toolbar__btn--active', btn.getAttribute('data-preview') === mode);
    });
  }

  // -----------------------------------------------------------------------
  // Drag Handle (Split Pane Resizer)
  // -----------------------------------------------------------------------

  function initDragHandle() {
    var isDragging = false;
    var startX = 0;
    var startWidth = 0;

    handle.addEventListener('mousedown', function (e) {
      isDragging = true;
      startX = e.clientX;
      startWidth = editorPane.offsetWidth;
      handle.classList.add('pg-handle--active');
      document.body.style.cursor = 'col-resize';
      document.body.style.userSelect = 'none';
      e.preventDefault();
    });

    document.addEventListener('mousemove', function (e) {
      if (!isDragging) return;
      var dx = e.clientX - startX;
      var newWidth = startWidth + dx;
      var minW = 200;
      var maxW = window.innerWidth - 200 - handle.offsetWidth;
      newWidth = Math.max(minW, Math.min(maxW, newWidth));
      editorPane.style.width = newWidth + 'px';
    });

    document.addEventListener('mouseup', function () {
      if (!isDragging) return;
      isDragging = false;
      handle.classList.remove('pg-handle--active');
      document.body.style.cursor = '';
      document.body.style.userSelect = '';
    });
  }

  // -----------------------------------------------------------------------
  // Status & Toast
  // -----------------------------------------------------------------------

  /**
   * Update the status bar.
   */
  function updateStatus() {
    if (statusDot && statusText) {
      if (isDirty) {
        statusDot.classList.add('pg-status__dot--error');
        statusText.textContent = 'Unsaved changes';
      } else {
        statusDot.classList.remove('pg-status__dot--error');
        statusText.textContent = 'Ready';
      }
    }
  }

  var toastTimer = null;

  /**
   * Show a toast notification.
   * @param {string} message
   * @param {boolean} [isError]
   */
  function showToast(message, isError) {
    if (!toast) return;
    if (toastTimer) clearTimeout(toastTimer);

    toast.textContent = message;
    toast.classList.toggle('pg-toast--error', !!isError);
    toast.classList.add('pg-toast--visible');

    toastTimer = setTimeout(function () {
      toast.classList.remove('pg-toast--visible');
    }, 2500);
  }

  // -----------------------------------------------------------------------
  // Initialization
  // -----------------------------------------------------------------------

  document.addEventListener('DOMContentLoaded', function () {
    // Grab DOM elements
    iframe = document.getElementById('catalog-frame');
    themeSelect = document.getElementById('theme-select');
    editorPane = document.querySelector('.pg-editor-pane');
    previewWrapper = document.querySelector('.pg-preview-wrapper');
    handle = document.querySelector('.pg-handle');
    toast = document.getElementById('pg-toast');
    dialogBackdrop = document.getElementById('save-dialog');
    dialogInput = document.getElementById('save-dialog-input');
    statusDot = document.querySelector('.pg-status__dot');
    statusText = document.getElementById('status-text');

    var editorTextarea = document.getElementById('editor-textarea');
    var editorHighlight = document.getElementById('editor-highlight');
    var editorGutter = document.getElementById('editor-gutter');

    // Initialize editor
    PulsarEditor.init({
      textarea: editorTextarea,
      highlight: editorHighlight,
      gutter: editorGutter,
      onChange: function (css) {
        isDirty = true;
        updateStatus();
        injectCSS(css);
      },
      onSave: handleSave,
    });

    // Fetch CSRF token, then load themes
    fetchCsrfToken().then(function () {
      return refreshThemeList().then(function () {
        switchTheme('default');
      });
    });

    // Theme select change
    themeSelect.addEventListener('change', function () {
      switchTheme(themeSelect.value);
    });

    // Save button
    document.getElementById('btn-save').addEventListener('click', handleSave);

    // Save As button
    var btnSaveAs = document.getElementById('btn-save-as');
    if (btnSaveAs) {
      btnSaveAs.addEventListener('click', openSaveDialog);
    }

    // Reset button
    document.getElementById('btn-reset').addEventListener('click', handleReset);

    // Responsive preview buttons
    document.querySelectorAll('[data-preview]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        setPreviewMode(btn.getAttribute('data-preview'));
      });
    });

    // Delete theme button
    var btnDelete = document.getElementById('btn-delete');
    if (btnDelete) {
      btnDelete.addEventListener('click', function () {
        if (currentThemeName === 'default') {
          showToast('Cannot delete the default theme.', true);
          return;
        }
        if (!confirm('Delete theme "' + currentThemeName + '"? This cannot be undone.')) {
          return;
        }
        deleteTheme(currentThemeName)
          .then(function () {
            showToast('Theme "' + currentThemeName + '" deleted');
            currentThemeName = 'default';
            return refreshThemeList();
          })
          .then(function () {
            themeSelect.value = 'default';
            switchTheme('default');
          })
          .catch(function (err) {
            showToast(err.message, true);
          });
      });
    }

    // Save dialog events
    document.getElementById('save-dialog-confirm').addEventListener('click', confirmSaveDialog);
    document.getElementById('save-dialog-cancel').addEventListener('click', closeSaveDialog);

    dialogInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        confirmSaveDialog();
      }
      if (e.key === 'Escape') {
        e.preventDefault();
        closeSaveDialog();
      }
    });

    dialogBackdrop.addEventListener('click', function (e) {
      if (e.target === dialogBackdrop) {
        closeSaveDialog();
      }
    });

    // Drag handle
    initDragHandle();

    // Set initial preview mode
    setPreviewMode('desktop');

    // Status
    updateStatus();
  });
})();
