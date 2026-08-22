/**
 * Block transform system for the page builder.
 *
 * Registers bidirectional and unidirectional transforms between block types.
 * Each transform defines how to convert block data from one type to another,
 * preserving content where possible.
 */

export interface TransformRule {
  from: string;
  to: string;
  label: string;
  icon: string;
  transform: (data: Record<string, unknown>) => Record<string, unknown>;
}

export class BlockTransformRegistry {
  private readonly rules: TransformRule[] = [];

  /** Register a transform rule. */
  register(rule: TransformRule): void {
    this.rules.push(rule);
  }

  /** Register a bidirectional transform (A -> B and B -> A). */
  registerBidirectional(
    typeA: string,
    typeB: string,
    labelAtoB: string,
    labelBtoA: string,
    icon: string,
    transformAtoB: (data: Record<string, unknown>) => Record<string, unknown>,
    transformBtoA: (data: Record<string, unknown>) => Record<string, unknown>,
  ): void {
    this.register({ from: typeA, to: typeB, label: labelAtoB, icon, transform: transformAtoB });
    this.register({ from: typeB, to: typeA, label: labelBtoA, icon, transform: transformBtoA });
  }

  /** Get all transforms available for a given block type. */
  getTransformsFor(fromType: string): TransformRule[] {
    return this.rules.filter((r) => r.from === fromType);
  }

  /** Apply a transform, returning the new block data for the target type. */
  apply(
    fromType: string,
    toType: string,
    data: Record<string, unknown>,
  ): Record<string, unknown> | null {
    const rule = this.rules.find((r) => r.from === fromType && r.to === toType);
    if (!rule) return null;
    return rule.transform(structuredClone(data));
  }
}

/** Shared singleton with built-in transforms. */
export const blockTransformRegistry = new BlockTransformRegistry();

// Heading <-> Paragraph
blockTransformRegistry.registerBidirectional(
  'heading',
  'paragraph',
  'Paragraph',
  'Heading',
  'H',
  (data) => ({ text: data['text'] ?? '', alignment: data['alignment'] ?? 'left' }),
  (data) => ({ text: data['text'] ?? '', level: 2 }),
);

// Quote -> Paragraph
blockTransformRegistry.register({
  from: 'quote',
  to: 'paragraph',
  label: 'Paragraph',
  icon: '\u00B6',
  transform: (data) => ({ text: data['text'] ?? '' }),
});

// Paragraph -> Quote
blockTransformRegistry.register({
  from: 'paragraph',
  to: 'quote',
  label: 'Quote',
  icon: '\u201C',
  transform: (data) => ({ text: data['text'] ?? '', citation: '' }),
});

// Code -> Paragraph
blockTransformRegistry.register({
  from: 'code',
  to: 'paragraph',
  label: 'Paragraph',
  icon: '\u00B6',
  transform: (data) => ({ text: typeof data['code'] === 'string' ? data['code'] : '' }),
});

// Paragraph -> Code
blockTransformRegistry.register({
  from: 'paragraph',
  to: 'code',
  label: 'Code',
  icon: '<>',
  transform: (data) => ({
    code: typeof data['text'] === 'string' ? data['text'] : '',
    language: 'plaintext',
  }),
});

// List -> Paragraph (join items)
blockTransformRegistry.register({
  from: 'list',
  to: 'paragraph',
  label: 'Paragraph',
  icon: '\u00B6',
  transform: (data) => {
    const items = Array.isArray(data['items']) ? data['items'] : [];
    const text = items.filter((i): i is string => typeof i === 'string').join('<br>');
    return { text };
  },
});

// Paragraph -> List (split by line breaks)
blockTransformRegistry.register({
  from: 'paragraph',
  to: 'list',
  label: 'List',
  icon: '\u2022',
  transform: (data) => {
    const text = typeof data['text'] === 'string' ? data['text'] : '';
    const items = text
      .split(/<br\s*\/?>|\n/)
      .map((s) => s.trim())
      .filter((s) => s.length > 0);
    return { items: items.length > 0 ? items : [''], ordered: false };
  },
});

// Heading level changes (H1-H6)
for (let from = 1; from <= 6; from++) {
  for (let to = 1; to <= 6; to++) {
    if (from === to) continue;
    blockTransformRegistry.register({
      from: 'heading',
      to: 'heading',
      label: `Heading ${to}`,
      icon: `H${to}`,
      transform: (data) => ({ ...data, level: to }),
    });
  }
}

/**
 * Transform menu component shown when the user clicks the transform
 * button in the block toolbar.
 */
export class BlockTransformMenu {
  private menuEl: HTMLElement | null = null;
  private readonly onTransform: (
    blockId: string,
    toType: string,
    newData: Record<string, unknown>,
  ) => void;

  constructor(
    onTransform: (blockId: string, toType: string, newData: Record<string, unknown>) => void,
  ) {
    this.onTransform = onTransform;
  }

  show(
    blockId: string,
    blockType: string,
    blockData: Record<string, unknown>,
    anchorRect: DOMRect,
  ): void {
    this.hide();

    const transforms = blockTransformRegistry.getTransformsFor(blockType);
    if (transforms.length === 0) return;

    // Deduplicate heading-level transforms: for heading->heading, only show distinct target levels
    const seen = new Set<string>();
    const uniqueTransforms = transforms.filter((t) => {
      // For heading->heading, use the label as key since "to" is always "heading"
      const key = t.from === t.to ? `${t.to}:${t.label}` : t.to;
      if (seen.has(key)) return false;
      seen.add(key);
      // Don't show transform to same level
      if (t.from === 'heading' && t.to === 'heading') {
        const currentLevel = typeof blockData['level'] === 'number' ? blockData['level'] : 2;
        const targetLabel = `Heading ${currentLevel}`;
        return t.label !== targetLabel;
      }
      return true;
    });

    if (uniqueTransforms.length === 0) return;

    const menu = document.createElement('div');
    menu.className = 'pb-transform-menu';
    menu.setAttribute('role', 'menu');
    menu.setAttribute('aria-label', 'Transform block');

    const title = document.createElement('div');
    title.className = 'pb-transform-menu__title';
    title.textContent = 'Transform to:';
    menu.appendChild(title);

    const items: HTMLButtonElement[] = [];

    for (const transform of uniqueTransforms) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'pb-transform-menu__item';
      btn.setAttribute('role', 'menuitem');
      btn.textContent = `${transform.icon} ${transform.label}`;

      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const newData = blockTransformRegistry.apply(blockType, transform.to, blockData);
        if (newData) {
          this.onTransform(blockId, transform.to, newData);
        }
        this.hide();
      });

      menu.appendChild(btn);
      items.push(btn);
    }

    // Keyboard navigation
    menu.addEventListener('keydown', (e) => {
      const currentIndex = items.indexOf(document.activeElement as HTMLButtonElement);
      switch (e.key) {
        case 'ArrowDown': {
          e.preventDefault();
          const next = (currentIndex + 1) % items.length;
          items[next]?.focus();
          break;
        }
        case 'ArrowUp': {
          e.preventDefault();
          const prev = (currentIndex - 1 + items.length) % items.length;
          items[prev]?.focus();
          break;
        }
        case 'Escape': {
          e.preventDefault();
          this.hide();
          break;
        }
        case 'Home': {
          e.preventDefault();
          items[0]?.focus();
          break;
        }
        case 'End': {
          e.preventDefault();
          items[items.length - 1]?.focus();
          break;
        }
      }
    });

    // Position below the anchor
    menu.style.position = 'fixed';
    menu.style.left = `${anchorRect.left}px`;
    menu.style.top = `${anchorRect.bottom + 4}px`;
    menu.style.zIndex = '1001';

    document.body.appendChild(menu);
    this.menuEl = menu;

    // Focus first item
    items[0]?.focus();

    // Close on outside click
    const closeHandler = (e: Event): void => {
      if (!menu.contains(e.target as Node)) {
        this.hide();
        document.removeEventListener('mousedown', closeHandler);
      }
    };
    setTimeout(() => document.addEventListener('mousedown', closeHandler), 0);
  }

  hide(): void {
    this.menuEl?.remove();
    this.menuEl = null;
  }

  destroy(): void {
    this.hide();
  }
}
