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
});
