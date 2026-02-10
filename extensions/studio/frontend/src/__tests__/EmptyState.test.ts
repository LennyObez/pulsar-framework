import { describe, it, expect } from 'vitest';
import { renderEmptyState } from '../components/EmptyState.js';

describe('renderEmptyState', () => {
  it('should render title and message', () => {
    const html = renderEmptyState({
      title: 'No Data',
      message: 'Nothing to display.',
    });

    expect(html).toContain('No Data');
    expect(html).toContain('Nothing to display.');
    expect(html).not.toContain('<a ');
  });

  it('should render back link when provided', () => {
    const html = renderEmptyState({
      title: 'Empty',
      message: 'No events.',
      backUrl: '/studio/console',
      backLabel: 'Back to Console',
    });

    expect(html).toContain('href="/studio/console"');
    expect(html).toContain('Back to Console');
  });

  it('should use default back label', () => {
    const html = renderEmptyState({
      title: 'Empty',
      message: 'No events.',
      backUrl: '/studio/console',
    });

    expect(html).toContain('Go Back');
  });

  it('should escape XSS in title and message', () => {
    const html = renderEmptyState({
      title: '<script>alert("xss")</script>',
      message: '<img src=x onerror=alert(1)>',
    });

    expect(html).not.toContain('<script>');
    expect(html).not.toContain('<img');
    expect(html).toContain('&lt;script&gt;');
    expect(html).toContain('&lt;img');
  });

  it('should reject javascript: URLs', () => {
    const html = renderEmptyState({
      title: 'Test',
      message: 'Test message',
      backUrl: 'javascript:alert(1)',
      backLabel: 'Click me',
    });

    // Should not render the link at all
    expect(html).not.toContain('href=');
    expect(html).not.toContain('javascript:');
  });

  it('should reject data: URLs', () => {
    const html = renderEmptyState({
      title: 'Test',
      message: 'Test message',
      backUrl: 'data:text/html,<script>alert(1)</script>',
      backLabel: 'Click me',
    });

    // Should not render the link at all
    expect(html).not.toContain('href=');
    expect(html).not.toContain('data:');
  });

  it('should allow relative URLs starting with /', () => {
    const html = renderEmptyState({
      title: 'Test',
      message: 'Test message',
      backUrl: '/studio/console',
      backLabel: 'Go to Console',
    });

    expect(html).toContain('href="/studio/console"');
  });

  it('should allow https URLs', () => {
    const html = renderEmptyState({
      title: 'Test',
      message: 'Test message',
      backUrl: 'https://example.com/path',
      backLabel: 'External Link',
    });

    expect(html).toContain('href="https://example.com/path"');
  });
});
