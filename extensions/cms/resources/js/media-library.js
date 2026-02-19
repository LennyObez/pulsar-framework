/**
 * CMS Media Library — vanilla JS module.
 *
 * Handles drag-and-drop upload, grid/list view toggle, upload progress,
 * clipboard copy, and replace-file interactions.
 */
(function () {
  'use strict';

  /** @type {HTMLElement|null} */
  const root = document.querySelector('[data-cms-media-library]');
  if (!root) return;

  // ------------------------------------------------------------------
  // Grid / List view toggle
  // ------------------------------------------------------------------
  const grid = root.querySelector('[data-cms-media-grid]');
  const list = root.querySelector('[data-cms-media-list]');
  const viewBtns = root.querySelectorAll('[data-cms-media-view]');

  viewBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      const mode = btn.getAttribute('data-cms-media-view');

      viewBtns.forEach(function (b) {
        b.setAttribute('aria-pressed', 'false');
      });
      btn.setAttribute('aria-pressed', 'true');

      if (mode === 'grid' && grid && list) {
        grid.removeAttribute('hidden');
        list.setAttribute('hidden', '');
      } else if (mode === 'list' && grid && list) {
        list.removeAttribute('hidden');
        grid.setAttribute('hidden', '');
      }
    });
  });

  // ------------------------------------------------------------------
  // Select-all checkbox (works for both grid and list)
  // ------------------------------------------------------------------
  const selectAllCheckboxes = root.querySelectorAll('[data-cms-select-all]');

  selectAllCheckboxes.forEach(function (selectAll) {
    selectAll.addEventListener('change', function () {
      const form = selectAll.closest('form');
      if (!form) return;

      form.querySelectorAll('input[name="ids[]"]').forEach(function (cb) {
        cb.checked = selectAll.checked;
      });
    });
  });

  // ------------------------------------------------------------------
  // Drag-and-drop upload
  // ------------------------------------------------------------------
  /** @type {HTMLElement|null} */
  const uploadZone = root.querySelector('[data-cms-upload-zone]');
  /** @type {HTMLInputElement|null} */
  const uploadInput = root.querySelector('[data-cms-upload-input]');
  /** @type {HTMLElement|null} */
  const uploadProgress = root.querySelector('[data-cms-upload-progress]');
  /** @type {HTMLElement|null} */
  const uploadFileList = root.querySelector('[data-cms-upload-file-list]');
  /** @type {HTMLElement|null} */
  const uploadTrigger = root.querySelector('[data-cms-media-upload-trigger]');

  if (uploadTrigger && uploadInput) {
    uploadTrigger.addEventListener('click', function () {
      uploadInput.click();
    });
  }

  if (uploadInput) {
    uploadInput.addEventListener('change', function () {
      if (uploadInput.files && uploadInput.files.length > 0) {
        uploadFiles(uploadInput.files);
      }
    });
  }

  if (uploadZone) {
    uploadZone.addEventListener('dragover', function (e) {
      e.preventDefault();
      e.stopPropagation();
      uploadZone.classList.add('cms-upload-zone--dragover');
    });

    uploadZone.addEventListener('dragleave', function (e) {
      e.preventDefault();
      e.stopPropagation();
      uploadZone.classList.remove('cms-upload-zone--dragover');
    });

    uploadZone.addEventListener('drop', function (e) {
      e.preventDefault();
      e.stopPropagation();
      uploadZone.classList.remove('cms-upload-zone--dragover');

      if (e.dataTransfer && e.dataTransfer.files.length > 0) {
        uploadFiles(e.dataTransfer.files);
      }
    });

    uploadZone.addEventListener('click', function (e) {
      if (e.target === uploadZone || e.target.closest('.cms-upload-zone__inner')) {
        if (uploadInput) uploadInput.click();
      }
    });
  }

  /**
   * Upload an array of files via XHR with progress reporting.
   *
   * @param {FileList} files
   */
  function uploadFiles(files) {
    if (!uploadProgress || !uploadFileList) return;

    uploadProgress.removeAttribute('hidden');
    uploadFileList.innerHTML = '';

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrfInput = document.querySelector('input[name="_token"]');
    var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : csrfInput ? csrfInput.value : '';

    for (var i = 0; i < files.length; i++) {
      uploadSingleFile(files[i], csrfToken);
    }
  }

  /**
   * Upload a single file with progress bar.
   *
   * @param {File} file
   * @param {string} csrfToken
   */
  function uploadSingleFile(file, csrfToken) {
    var li = document.createElement('li');
    li.className = 'cms-upload-zone__file-item';
    li.innerHTML =
      '<span class="cms-upload-zone__file-name">' +
      escapeHtml(file.name) +
      '</span>' +
      '<span class="cms-upload-zone__file-size">' +
      formatFileSize(file.size) +
      '</span>' +
      '<div class="cms-upload-zone__progress-bar">' +
      '<div class="cms-upload-zone__progress-fill" style="width: 0%"></div>' +
      '</div>' +
      '<span class="cms-upload-zone__file-status">Uploading...</span>';

    if (uploadFileList) {
      uploadFileList.appendChild(li);
    }

    var progressFill = li.querySelector('.cms-upload-zone__progress-fill');
    var statusSpan = li.querySelector('.cms-upload-zone__file-status');

    var formData = new FormData();
    formData.append('file', file);
    if (csrfToken) {
      formData.append('_token', csrfToken);
    }

    var xhr = new XMLHttpRequest();

    xhr.upload.addEventListener('progress', function (e) {
      if (e.lengthComputable && progressFill) {
        var pct = Math.round((e.loaded / e.total) * 100);
        progressFill.style.width = pct + '%';
      }
    });

    xhr.addEventListener('load', function () {
      if (xhr.status >= 200 && xhr.status < 300) {
        if (progressFill) progressFill.style.width = '100%';
        if (statusSpan) {
          statusSpan.textContent = 'Complete';
          statusSpan.classList.add('cms-upload-zone__file-status--success');
        }
        li.classList.add('cms-upload-zone__file-item--complete');
      } else {
        if (statusSpan) {
          statusSpan.textContent = 'Failed (' + xhr.status + ')';
          statusSpan.classList.add('cms-upload-zone__file-status--error');
        }
        li.classList.add('cms-upload-zone__file-item--error');
      }
    });

    xhr.addEventListener('error', function () {
      if (statusSpan) {
        statusSpan.textContent = 'Network error';
        statusSpan.classList.add('cms-upload-zone__file-status--error');
      }
      li.classList.add('cms-upload-zone__file-item--error');
    });

    xhr.open('POST', '/admin/cms/media', true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.send(formData);
  }

  // ------------------------------------------------------------------
  // Copy URL to clipboard
  // ------------------------------------------------------------------
  document.querySelectorAll('[data-cms-copy-url]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var url = btn.getAttribute('data-cms-copy-url');
      if (!url) return;

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard
          .writeText(url)
          .then(function () {
            showCopyFeedback(btn, 'Copied!');
          })
          .catch(function () {
            fallbackCopy(url, btn);
          });
      } else {
        fallbackCopy(url, btn);
      }
    });
  });

  /**
   * Fallback clipboard copy using a temporary textarea.
   *
   * @param {string} text
   * @param {HTMLElement} btn
   */
  function fallbackCopy(text, btn) {
    var textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.left = '-9999px';
    document.body.appendChild(textarea);
    textarea.select();

    try {
      document.execCommand('copy');
      showCopyFeedback(btn, 'Copied!');
    } catch (_e) {
      showCopyFeedback(btn, 'Failed');
    }

    document.body.removeChild(textarea);
  }

  /**
   * Show brief copy feedback on a button.
   *
   * @param {HTMLElement} btn
   * @param {string} msg
   */
  function showCopyFeedback(btn, msg) {
    var original = btn.textContent;
    btn.textContent = msg;
    setTimeout(function () {
      btn.textContent = original;
    }, 2000);
  }

  // ------------------------------------------------------------------
  // Replace file toggle
  // ------------------------------------------------------------------
  var replaceBtn = document.querySelector('[data-cms-media-replace]');
  var replaceForm = document.querySelector('[data-cms-replace-form]');
  var replaceCancel = document.querySelector('[data-cms-replace-cancel]');

  if (replaceBtn && replaceForm) {
    replaceBtn.addEventListener('click', function () {
      replaceForm.removeAttribute('hidden');
      replaceBtn.setAttribute('hidden', '');
    });
  }

  if (replaceCancel && replaceForm && replaceBtn) {
    replaceCancel.addEventListener('click', function () {
      replaceForm.setAttribute('hidden', '');
      replaceBtn.removeAttribute('hidden');
    });
  }

  // ------------------------------------------------------------------
  // Alt text locale tabs
  // ------------------------------------------------------------------
  var altTabs = document.querySelectorAll('[data-cms-alt-locale]');
  var altPanels = document.querySelectorAll('[data-cms-alt-panel]');

  altTabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
      var locale = tab.getAttribute('data-cms-alt-locale');

      altTabs.forEach(function (t) {
        t.classList.remove('cms-media-show__locale-tab--active');
        t.setAttribute('aria-selected', 'false');
      });
      tab.classList.add('cms-media-show__locale-tab--active');
      tab.setAttribute('aria-selected', 'true');

      altPanels.forEach(function (panel) {
        if (panel.getAttribute('data-cms-alt-panel') === locale) {
          panel.classList.remove('cms-media-show__alt-panel--hidden');
        } else {
          panel.classList.add('cms-media-show__alt-panel--hidden');
        }
      });
    });
  });

  // ------------------------------------------------------------------
  // Collapsible panels (EXIF data)
  // ------------------------------------------------------------------
  document.querySelectorAll('[data-cms-collapsible-toggle]').forEach(function (toggle) {
    toggle.addEventListener('click', function () {
      var body = toggle.closest('.cms-sidebar-panel').querySelector('[data-cms-collapsible-body]');
      if (!body) return;

      var isExpanded = toggle.getAttribute('aria-expanded') === 'true';
      toggle.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');

      if (isExpanded) {
        body.setAttribute('hidden', '');
      } else {
        body.removeAttribute('hidden');
      }
    });
  });

  // ------------------------------------------------------------------
  // Helpers
  // ------------------------------------------------------------------

  /**
   * Escape HTML entities in a string.
   *
   * @param {string} str
   * @returns {string}
   */
  function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
  }

  /**
   * Format bytes into a human-readable size string.
   *
   * @param {number} bytes
   * @returns {string}
   */
  function formatFileSize(bytes) {
    if (bytes === 0) return '0 B';
    var units = ['B', 'KB', 'MB', 'GB'];
    var i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
  }
})();
