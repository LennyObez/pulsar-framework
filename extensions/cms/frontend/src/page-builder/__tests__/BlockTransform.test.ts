import { describe, it, expect } from 'vitest';
import { BlockTransformRegistry, blockTransformRegistry } from '../BlockTransform.js';

describe('BlockTransformRegistry', () => {
  it('registers and retrieves transforms', () => {
    const registry = new BlockTransformRegistry();
    registry.register({
      from: 'a',
      to: 'b',
      label: 'To B',
      icon: 'B',
      transform: (data) => ({ converted: true, ...data }),
    });

    const transforms = registry.getTransformsFor('a');
    expect(transforms).toHaveLength(1);
    expect(transforms[0]?.to).toBe('b');
  });

  it('returns empty array for unknown type', () => {
    const registry = new BlockTransformRegistry();
    expect(registry.getTransformsFor('nonexistent')).toEqual([]);
  });

  it('applies a transform', () => {
    const registry = new BlockTransformRegistry();
    registry.register({
      from: 'heading',
      to: 'paragraph',
      label: 'Paragraph',
      icon: 'P',
      transform: (data) => ({ text: data['text'] }),
    });

    const result = registry.apply('heading', 'paragraph', { text: 'Hello', level: 2 });
    expect(result).toEqual({ text: 'Hello' });
  });

  it('returns null for missing transform', () => {
    const registry = new BlockTransformRegistry();
    const result = registry.apply('heading', 'nonexistent', {});
    expect(result).toBeNull();
  });

  it('registerBidirectional creates both directions', () => {
    const registry = new BlockTransformRegistry();
    registry.registerBidirectional(
      'a',
      'b',
      'To B',
      'To A',
      'X',
      (data) => ({ ...data, direction: 'a-to-b' }),
      (data) => ({ ...data, direction: 'b-to-a' }),
    );

    expect(registry.getTransformsFor('a')).toHaveLength(1);
    expect(registry.getTransformsFor('b')).toHaveLength(1);

    expect(registry.apply('a', 'b', {})).toEqual({ direction: 'a-to-b' });
    expect(registry.apply('b', 'a', {})).toEqual({ direction: 'b-to-a' });
  });

  it('does not mutate original data', () => {
    const registry = new BlockTransformRegistry();
    registry.register({
      from: 'a',
      to: 'b',
      label: 'B',
      icon: 'B',
      transform: (data) => {
        data['mutated'] = true;
        return data;
      },
    });

    const original = { text: 'hello' };
    registry.apply('a', 'b', original);
    expect(original).not.toHaveProperty('mutated');
  });
});

describe('built-in transforms', () => {
  it('transforms heading to paragraph', () => {
    const result = blockTransformRegistry.apply('heading', 'paragraph', {
      text: 'Hello World',
      level: 2,
    });
    expect(result).toEqual({ text: 'Hello World', alignment: 'left' });
  });

  it('transforms paragraph to heading', () => {
    const result = blockTransformRegistry.apply('paragraph', 'heading', {
      text: 'Some text',
      alignment: 'center',
    });
    expect(result).toEqual({ text: 'Some text', level: 2 });
  });

  it('transforms quote to paragraph', () => {
    const result = blockTransformRegistry.apply('quote', 'paragraph', {
      text: 'A quote',
      citation: 'Author',
    });
    expect(result).toEqual({ text: 'A quote' });
  });

  it('transforms paragraph to quote', () => {
    const result = blockTransformRegistry.apply('paragraph', 'quote', {
      text: 'Some words',
    });
    expect(result).toEqual({ text: 'Some words', citation: '' });
  });

  it('transforms code to paragraph', () => {
    const result = blockTransformRegistry.apply('code', 'paragraph', {
      code: 'console.log("hi")',
      language: 'javascript',
    });
    expect(result).toEqual({ text: 'console.log("hi")' });
  });

  it('transforms paragraph to code', () => {
    const result = blockTransformRegistry.apply('paragraph', 'code', {
      text: 'some code',
    });
    expect(result).toEqual({ code: 'some code', language: 'plaintext' });
  });

  it('transforms list to paragraph (join items)', () => {
    const result = blockTransformRegistry.apply('list', 'paragraph', {
      items: ['First', 'Second', 'Third'],
      ordered: false,
    });
    expect(result).toEqual({ text: 'First<br>Second<br>Third' });
  });

  it('transforms paragraph to list (split by br)', () => {
    const result = blockTransformRegistry.apply('paragraph', 'list', {
      text: 'Line 1<br>Line 2<br>Line 3',
    });
    expect(result).toEqual({
      items: ['Line 1', 'Line 2', 'Line 3'],
      ordered: false,
    });
  });

  it('paragraph to list with empty text yields single empty item', () => {
    const result = blockTransformRegistry.apply('paragraph', 'list', {
      text: '',
    });
    expect(result).toEqual({ items: [''], ordered: false });
  });

  it('has heading level transforms', () => {
    const transforms = blockTransformRegistry.getTransformsFor('heading');
    // Should have: paragraph + heading levels (H1-H6 minus current)
    expect(transforms.length).toBeGreaterThan(5);
  });
});
