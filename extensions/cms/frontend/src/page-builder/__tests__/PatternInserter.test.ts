import { describe, it, expect } from 'vitest';
import {
  BlockPatternRegistry,
  blockPatternRegistry,
  type BlockPattern,
} from '../PatternInserter.js';

function makePattern(overrides: Partial<BlockPattern> = {}): BlockPattern {
  return {
    name: 'test-pattern',
    title: 'Test Pattern',
    description: 'A test pattern',
    category: 'Testing',
    icon: 'T',
    blocks: [{ type: 'paragraph', data: { text: 'hello' } }],
    ...overrides,
  };
}

describe('BlockPatternRegistry', () => {
  it('registers and retrieves a pattern', () => {
    const registry = new BlockPatternRegistry();
    const pattern = makePattern();
    registry.register(pattern);

    expect(registry.get('test-pattern')).toBe(pattern);
  });

  it('returns undefined for unknown pattern', () => {
    const registry = new BlockPatternRegistry();
    expect(registry.get('nonexistent')).toBeUndefined();
  });

  it('lists patterns by category', () => {
    const registry = new BlockPatternRegistry();
    registry.register(makePattern({ name: 'a', category: 'Sections' }));
    registry.register(makePattern({ name: 'b', category: 'Commerce' }));
    registry.register(makePattern({ name: 'c', category: 'Sections' }));

    const sections = registry.getByCategory('Sections');
    expect(sections).toHaveLength(2);
    expect(sections.map((p) => p.name)).toEqual(['a', 'c']);
  });

  it('lists all categories', () => {
    const registry = new BlockPatternRegistry();
    registry.register(makePattern({ name: 'a', category: 'Sections' }));
    registry.register(makePattern({ name: 'b', category: 'Commerce' }));
    registry.register(makePattern({ name: 'c', category: 'Sections' }));

    const categories = registry.getCategories();
    expect(categories).toContain('Sections');
    expect(categories).toContain('Commerce');
    expect(categories).toHaveLength(2);
  });

  it('lists all patterns', () => {
    const registry = new BlockPatternRegistry();
    registry.register(makePattern({ name: 'a' }));
    registry.register(makePattern({ name: 'b' }));

    expect(registry.all()).toHaveLength(2);
  });

  it('overwrites pattern with same name', () => {
    const registry = new BlockPatternRegistry();
    registry.register(makePattern({ name: 'a', title: 'Original' }));
    registry.register(makePattern({ name: 'a', title: 'Updated' }));

    expect(registry.get('a')?.title).toBe('Updated');
    expect(registry.all()).toHaveLength(1);
  });
});

describe('built-in patterns', () => {
  it('includes hero-section pattern', () => {
    const pattern = blockPatternRegistry.get('hero-section');
    expect(pattern).toBeDefined();
    expect(pattern?.category).toBe('Sections');
    expect(pattern?.blocks.length).toBeGreaterThan(0);
  });

  it('includes feature-grid pattern', () => {
    const pattern = blockPatternRegistry.get('feature-grid');
    expect(pattern).toBeDefined();
    expect(pattern?.blocks.some((b) => b.type === 'columns')).toBe(true);
  });

  it('includes testimonials-row pattern', () => {
    const pattern = blockPatternRegistry.get('testimonials-row');
    expect(pattern).toBeDefined();
  });

  it('includes pricing-page pattern', () => {
    const pattern = blockPatternRegistry.get('pricing-page');
    expect(pattern).toBeDefined();
    expect(pattern?.category).toBe('Commerce');
  });

  it('includes contact-section pattern', () => {
    const pattern = blockPatternRegistry.get('contact-section');
    expect(pattern).toBeDefined();
    expect(
      pattern?.blocks.some(
        (b) => b.type === 'contact-form' || b.children?.some((c) => c.type === 'contact-form'),
      ),
    ).toBe(true);
  });

  it('includes faq-section pattern', () => {
    const pattern = blockPatternRegistry.get('faq-section');
    expect(pattern).toBeDefined();
    expect(pattern?.blocks.some((b) => b.type === 'accordion')).toBe(true);
  });

  it('has multiple categories', () => {
    const categories = blockPatternRegistry.getCategories();
    expect(categories.length).toBeGreaterThanOrEqual(2);
  });
});
