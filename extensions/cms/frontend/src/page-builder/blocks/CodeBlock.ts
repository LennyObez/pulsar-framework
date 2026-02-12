/**
 * Page builder code block.
 *
 * `<cms-pb-code>` renders a code textarea with a language selector dropdown
 * and line number display in the preview.
 */

import { escapeHtml } from '../../utils/escapeHtml.js';

const LANGUAGES = [
  'plaintext',
  'html',
  'css',
  'javascript',
  'typescript',
  'php',
  'python',
  'ruby',
  'java',
  'go',
  'rust',
  'sql',
  'bash',
  'json',
  'yaml',
  'xml',
  'markdown',
] as const;

export class CmsPbCode extends HTMLElement {
  private blockData: Record<string, unknown> = {};

  connectedCallback(): void {
    this.classList.add('pb-block-code');
    this.render();
  }

  setData(data: Record<string, unknown>): void {
    this.blockData = { ...data };
    this.render();
  }

  getData(): Record<string, unknown> {
    return { ...this.blockData };
  }

  private render(): void {
    this.innerHTML = '';

    const code = this.blockData['code'];
    const language = this.blockData['language'];
    const codeStr = typeof code === 'string' ? code : '';
    const langStr =
      typeof language === 'string' && LANGUAGES.includes(language as (typeof LANGUAGES)[number])
        ? language
        : 'plaintext';

    const wrapper = document.createElement('div');
    wrapper.className = 'pb-block-code__wrapper';

    // Language selector
    const toolbar = document.createElement('div');
    toolbar.className = 'pb-block-code__toolbar';

    const select = document.createElement('select');
    select.className = 'cms-input pb-block-code__lang-select';
    select.setAttribute('aria-label', 'Code language');

    for (const lang of LANGUAGES) {
      const option = document.createElement('option');
      option.value = lang;
      option.textContent = lang;
      if (lang === langStr) {
        option.selected = true;
      }
      select.appendChild(option);
    }

    select.addEventListener('change', () => {
      this.blockData['language'] = select.value;
      this.emitUpdate();
    });

    toolbar.appendChild(select);
    wrapper.appendChild(toolbar);

    // Code editor area with line numbers
    const editorArea = document.createElement('div');
    editorArea.className = 'pb-block-code__editor-area';

    const lineNumbers = document.createElement('div');
    lineNumbers.className = 'pb-block-code__line-numbers';
    lineNumbers.setAttribute('aria-hidden', 'true');
    this.updateLineNumbers(lineNumbers, codeStr);

    const textarea = document.createElement('textarea');
    textarea.className = 'pb-block-code__textarea';
    textarea.value = codeStr;
    textarea.spellcheck = false;
    textarea.setAttribute('aria-label', `${escapeHtml(langStr)} code editor`);
    textarea.wrap = 'off';

    textarea.addEventListener('input', () => {
      this.blockData['code'] = textarea.value;
      this.updateLineNumbers(lineNumbers, textarea.value);
      this.emitUpdate();
    });

    textarea.addEventListener('scroll', () => {
      lineNumbers.scrollTop = textarea.scrollTop;
    });

    editorArea.appendChild(lineNumbers);
    editorArea.appendChild(textarea);
    wrapper.appendChild(editorArea);

    this.appendChild(wrapper);
  }

  private updateLineNumbers(el: HTMLElement, code: string): void {
    const lines = code.split('\n').length;
    const numbers: string[] = [];
    for (let i = 1; i <= lines; i++) {
      numbers.push(String(i));
    }
    el.textContent = numbers.join('\n');
  }

  private emitUpdate(): void {
    this.dispatchEvent(
      new CustomEvent('block-update', {
        bubbles: true,
        detail: this.getData(),
      }),
    );
  }
}

customElements.define('cms-pb-code', CmsPbCode);
