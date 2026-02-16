/**
 * Pulsar UI Playground — CSS Editor
 *
 * Custom-built code editor for CSS editing with:
 * - Line numbers panel synced to scroll position
 * - CSS syntax highlighting via regex-based tokenizer
 * - Tab key handling (2-space indent)
 * - Auto-indent on Enter
 * - Ctrl+S save shortcut
 * - Real-time CSS injection into preview iframe
 *
 * Zero external dependencies.
 */
/* exported PulsarEditor */
'use strict';

var PulsarEditor = (function () {
  /** @type {HTMLTextAreaElement|null} */
  var textarea = null;
  /** @type {HTMLElement|null} */
  var highlight = null;
  /** @type {HTMLElement|null} */
  var gutter = null;
  /** @type {function(string): void} */
  var onChangeCallback = function () {};
  /** @type {function(): void} */
  var onSaveCallback = function () {};

  var TAB = '  ';

  /**
   * Initialize the editor.
   * @param {Object} config
   * @param {HTMLTextAreaElement} config.textarea
   * @param {HTMLElement} config.highlight
   * @param {HTMLElement} config.gutter
   * @param {function(string): void} [config.onChange]
   * @param {function(): void} [config.onSave]
   */
  function init(config) {
    textarea = config.textarea;
    highlight = config.highlight;
    gutter = config.gutter;
    onChangeCallback = config.onChange || function () {};
    onSaveCallback = config.onSave || function () {};

    textarea.addEventListener('input', handleInput);
    textarea.addEventListener('scroll', syncScroll);
    textarea.addEventListener('keydown', handleKeydown);

    updateHighlight();
    updateGutter();
  }

  /**
   * Get the current editor content.
   * @returns {string}
   */
  function getValue() {
    return textarea ? textarea.value : '';
  }

  /**
   * Set the editor content.
   * @param {string} value
   */
  function setValue(value) {
    if (!textarea) return;
    textarea.value = value;
    updateHighlight();
    updateGutter();
  }

  function handleInput() {
    updateHighlight();
    updateGutter();
    onChangeCallback(textarea.value);
  }

  /**
   * Handle keyboard shortcuts.
   * @param {KeyboardEvent} e
   */
  function handleKeydown(e) {
    // Ctrl+S / Cmd+S — save
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
      e.preventDefault();
      onSaveCallback();
      return;
    }

    // Tab — insert 2 spaces
    if (e.key === 'Tab' && !e.ctrlKey && !e.metaKey && !e.altKey) {
      e.preventDefault();

      var start = textarea.selectionStart;
      var end = textarea.selectionEnd;
      var value = textarea.value;

      if (e.shiftKey) {
        // Shift+Tab — unindent the current line
        var lineStart = value.lastIndexOf('\n', start - 1) + 1;
        var linePrefix = value.substring(lineStart, lineStart + TAB.length);

        if (linePrefix === TAB) {
          textarea.value = value.substring(0, lineStart) + value.substring(lineStart + TAB.length);
          textarea.selectionStart = Math.max(start - TAB.length, lineStart);
          textarea.selectionEnd = Math.max(end - TAB.length, lineStart);
        }
      } else if (start === end) {
        // No selection — insert tab
        textarea.value = value.substring(0, start) + TAB + value.substring(end);
        textarea.selectionStart = start + TAB.length;
        textarea.selectionEnd = start + TAB.length;
      } else {
        // Selection — indent all selected lines
        var lineStart2 = value.lastIndexOf('\n', start - 1) + 1;
        var before = value.substring(0, lineStart2);
        var selected = value.substring(lineStart2, end);
        var after = value.substring(end);
        var indented = TAB + selected.replace(/\n/g, '\n' + TAB);

        textarea.value = before + indented + after;
        textarea.selectionStart = start + TAB.length;
        textarea.selectionEnd =
          lineStart2 + indented.length - (after.length > 0 && indented.endsWith(TAB) ? 0 : 0);
      }

      handleInput();
      return;
    }

    // Enter — auto-indent
    if (e.key === 'Enter' && !e.ctrlKey && !e.metaKey) {
      e.preventDefault();

      var start2 = textarea.selectionStart;
      var value2 = textarea.value;

      // Get the current line to determine indentation
      var lineStart3 = value2.lastIndexOf('\n', start2 - 1) + 1;
      var currentLine = value2.substring(lineStart3, start2);

      // Match leading whitespace
      var indentMatch = currentLine.match(/^(\s*)/);
      var indent = indentMatch ? indentMatch[1] : '';

      // If the line ends with '{', add extra indent
      var trimmedBeforeCursor = value2.substring(lineStart3, start2).trimEnd();
      if (trimmedBeforeCursor.endsWith('{')) {
        indent += TAB;
      }

      var insertion = '\n' + indent;
      textarea.value =
        value2.substring(0, start2) + insertion + value2.substring(textarea.selectionEnd);
      textarea.selectionStart = start2 + insertion.length;
      textarea.selectionEnd = start2 + insertion.length;

      handleInput();
    }
  }

  /**
   * Sync scroll position between textarea and highlight/gutter.
   */
  function syncScroll() {
    if (!textarea || !highlight || !gutter) return;
    highlight.scrollTop = textarea.scrollTop;
    highlight.scrollLeft = textarea.scrollLeft;
    gutter.scrollTop = textarea.scrollTop;
  }

  /**
   * Update the syntax-highlighted overlay.
   */
  function updateHighlight() {
    if (!textarea || !highlight) return;
    highlight.innerHTML = tokenize(textarea.value);
  }

  /**
   * Update the line numbers gutter.
   */
  function updateGutter() {
    if (!textarea || !gutter) return;
    var lines = textarea.value.split('\n');
    var html = '';

    for (var i = 1; i <= lines.length; i++) {
      html += '<span class="pg-editor__gutter-line">' + i + '</span>';
    }

    gutter.innerHTML = html;
  }

  /**
   * CSS syntax tokenizer. Produces HTML with spans for each token type.
   * Handles: comments, at-rules, selectors, properties, values, braces,
   * strings, numbers, units, functions, !important.
   *
   * @param {string} css
   * @returns {string} HTML with syntax highlighting spans
   */
  function tokenize(css) {
    var result = '';
    var i = 0;
    var len = css.length;
    /** @type {'selector'|'property'|'value'} */
    var context = 'selector';
    var braceDepth = 0;

    while (i < len) {
      // Block comment: /* ... */
      if (css[i] === '/' && css[i + 1] === '*') {
        var endComment = css.indexOf('*/', i + 2);
        if (endComment === -1) endComment = len - 2;
        var commentText = css.substring(i, endComment + 2);
        result += '<span class="pg-tok-comment">' + esc(commentText) + '</span>';
        i = endComment + 2;
        continue;
      }

      // String (single or double quoted)
      if (css[i] === '"' || css[i] === "'") {
        var quote = css[i];
        var j = i + 1;
        while (j < len && css[j] !== quote) {
          if (css[j] === '\\') j++;
          j++;
        }
        var str = css.substring(i, j + 1);
        result += '<span class="pg-tok-string">' + esc(str) + '</span>';
        i = j + 1;
        continue;
      }

      // At-rule: @media, @keyframes, @import, etc.
      if (css[i] === '@') {
        var atEnd = i + 1;
        while (atEnd < len && /[a-zA-Z0-9-]/.test(css[atEnd])) {
          atEnd++;
        }
        var atRule = css.substring(i, atEnd);
        result += '<span class="pg-tok-atrule">' + esc(atRule) + '</span>';
        i = atEnd;
        continue;
      }

      // Opening brace
      if (css[i] === '{') {
        result += '<span class="pg-tok-brace">{</span>';
        braceDepth++;
        context = 'property';
        i++;
        continue;
      }

      // Closing brace
      if (css[i] === '}') {
        result += '<span class="pg-tok-brace">}</span>';
        braceDepth--;
        context = braceDepth > 0 ? 'property' : 'selector';
        i++;
        continue;
      }

      // Colon — switch from property to value
      if (css[i] === ':' && context === 'property') {
        result += '<span class="pg-tok-colon">:</span>';
        context = 'value';
        i++;
        continue;
      }

      // Semicolon — end of declaration
      if (css[i] === ';') {
        result += '<span class="pg-tok-colon">;</span>';
        if (braceDepth > 0) {
          context = 'property';
        }
        i++;
        continue;
      }

      // !important
      if (css[i] === '!' && context === 'value') {
        var impEnd = i + 1;
        while (impEnd < len && /[a-zA-Z]/.test(css[impEnd])) {
          impEnd++;
        }
        var impText = css.substring(i, impEnd);
        result += '<span class="pg-tok-important">' + esc(impText) + '</span>';
        i = impEnd;
        continue;
      }

      // Newline — preserve literally
      if (css[i] === '\n') {
        result += '\n';
        i++;
        continue;
      }

      // Whitespace
      if (/\s/.test(css[i])) {
        result += css[i];
        i++;
        continue;
      }

      // In value context: numbers and units
      if (context === 'value' && /[0-9.#-]/.test(css[i])) {
        var numEnd = i;
        // Handle hex colors
        if (css[i] === '#') {
          numEnd++;
          while (numEnd < len && /[0-9a-fA-F]/.test(css[numEnd])) numEnd++;
          result += '<span class="pg-tok-number">' + esc(css.substring(i, numEnd)) + '</span>';
          i = numEnd;
          continue;
        }
        // Handle negative numbers
        if (css[i] === '-' && numEnd + 1 < len && /[0-9.]/.test(css[numEnd + 1])) {
          numEnd++;
        }
        while (numEnd < len && /[0-9.]/.test(css[numEnd])) numEnd++;
        if (numEnd > i) {
          result += '<span class="pg-tok-number">' + esc(css.substring(i, numEnd)) + '</span>';
          // Check for unit
          var unitEnd = numEnd;
          while (unitEnd < len && /[a-zA-Z%]/.test(css[unitEnd])) unitEnd++;
          if (unitEnd > numEnd) {
            result +=
              '<span class="pg-tok-unit">' + esc(css.substring(numEnd, unitEnd)) + '</span>';
            numEnd = unitEnd;
          }
          i = numEnd;
          continue;
        }
      }

      // Function call: word followed by (
      if (/[a-zA-Z_-]/.test(css[i])) {
        var wordEnd = i;
        while (wordEnd < len && /[a-zA-Z0-9_-]/.test(css[wordEnd])) wordEnd++;

        var word = css.substring(i, wordEnd);

        // Check if this is a function (followed by parenthesis)
        if (wordEnd < len && css[wordEnd] === '(') {
          result += '<span class="pg-tok-function">' + esc(word) + '</span>';
          i = wordEnd;
          continue;
        }

        // Contextual coloring
        if (context === 'selector') {
          result += '<span class="pg-tok-selector">' + esc(word) + '</span>';
        } else if (context === 'property') {
          result += '<span class="pg-tok-property">' + esc(word) + '</span>';
        } else {
          result += '<span class="pg-tok-value">' + esc(word) + '</span>';
        }
        i = wordEnd;
        continue;
      }

      // Selector-context special chars: . # > + ~ * [ ] = ( )
      if (context === 'selector') {
        result += '<span class="pg-tok-selector">' + esc(css[i]) + '</span>';
        i++;
        continue;
      }

      // Parentheses in value context
      if (css[i] === '(' || css[i] === ')') {
        result += '<span class="pg-tok-brace">' + esc(css[i]) + '</span>';
        i++;
        continue;
      }

      // Default: output character as-is
      result += esc(css[i]);
      i++;
    }

    return result;
  }

  /**
   * Escape HTML special characters.
   * @param {string} str
   * @returns {string}
   */
  function esc(str) {
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  return {
    init: init,
    getValue: getValue,
    setValue: setValue,
  };
})();
