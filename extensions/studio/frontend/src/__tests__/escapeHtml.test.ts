import { describe, it, expect } from 'vitest';
import { escapeHtml } from '../utils/escapeHtml.js';

describe('escapeHtml', () => {
  it('should escape < and > characters', () => {
    expect(escapeHtml('<script>alert("xss")</script>')).toBe(
      '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;',
    );
  });

  it('should escape ampersands', () => {
    expect(escapeHtml('foo & bar')).toBe('foo &amp; bar');
  });

  it('should escape double quotes', () => {
    expect(escapeHtml('class="test"')).toBe('class=&quot;test&quot;');
  });

  it('should escape single quotes', () => {
    expect(escapeHtml("onClick='alert()")).toBe('onClick=&#039;alert()');
  });

  it('should pass through safe strings unchanged', () => {
    expect(escapeHtml('Hello, World!')).toBe('Hello, World!');
    expect(escapeHtml('plain text 123')).toBe('plain text 123');
    expect(escapeHtml('/studio/console')).toBe('/studio/console');
  });

  it('should handle empty string', () => {
    expect(escapeHtml('')).toBe('');
  });

  it('should escape all special characters in combination', () => {
    expect(escapeHtml('<a href="url" onClick=\'test\'>&data</a>')).toBe(
      '&lt;a href=&quot;url&quot; onClick=&#039;test&#039;&gt;&amp;data&lt;/a&gt;',
    );
  });
});
