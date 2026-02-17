/**
 * Block pattern inserter for the page builder.
 *
 * Block patterns are pre-composed collections of blocks that represent
 * common page sections (hero + CTA, feature grids, testimonials, etc.).
 * Users insert them as one-click starting points.
 */

export interface PatternBlockDefinition {
  type: string;
  data: Record<string, unknown>;
  children?: PatternBlockDefinition[];
}

export interface BlockPattern {
  readonly name: string;
  readonly title: string;
  readonly description: string;
  readonly category: string;
  readonly icon: string;
  readonly blocks: readonly PatternBlockDefinition[];
}

export class BlockPatternRegistry {
  private readonly patterns: Map<string, BlockPattern> = new Map();

  register(pattern: BlockPattern): void {
    this.patterns.set(pattern.name, pattern);
  }

  get(name: string): BlockPattern | undefined {
    return this.patterns.get(name);
  }

  getByCategory(category: string): BlockPattern[] {
    const result: BlockPattern[] = [];
    for (const pattern of this.patterns.values()) {
      if (pattern.category === category) {
        result.push(pattern);
      }
    }
    return result;
  }

  getCategories(): string[] {
    const cats = new Set<string>();
    for (const pattern of this.patterns.values()) {
      cats.add(pattern.category);
    }
    return [...cats];
  }

  all(): BlockPattern[] {
    return [...this.patterns.values()];
  }
}

/** Shared singleton with built-in patterns. */
export const blockPatternRegistry = new BlockPatternRegistry();

// --- Built-in patterns ---

blockPatternRegistry.register({
  name: 'hero-section',
  title: 'Hero Section',
  description: 'A full-width hero with heading, text, and call-to-action button.',
  category: 'Sections',
  icon: '\u2605',
  blocks: [
    {
      type: 'hero',
      data: {
        heading: 'Welcome to Our Website',
        subtext: 'A short description of what you do and why visitors should care.',
        buttonText: 'Get Started',
        buttonUrl: '#',
        overlay: true,
        backgroundImage: '',
      },
    },
    { type: 'spacer', data: { height: 20 } },
  ],
});

blockPatternRegistry.register({
  name: 'feature-grid',
  title: 'Feature Grid',
  description: 'A heading followed by three feature columns with icons.',
  category: 'Sections',
  icon: '\u2630',
  blocks: [
    { type: 'heading', data: { text: 'Our Features', level: 2 } },
    { type: 'spacer', data: { height: 16 } },
    {
      type: 'columns',
      data: { columnCount: 3 },
      children: [
        { type: 'icon', data: { icon: 'fa-bolt', size: 'lg', color: '', _columnIndex: 0 } },
        { type: 'heading', data: { text: 'Fast', level: 3, _columnIndex: 0 } },
        {
          type: 'paragraph',
          data: { text: 'Lightning-fast performance for your users.', _columnIndex: 0 },
        },
        { type: 'icon', data: { icon: 'fa-shield-alt', size: 'lg', color: '', _columnIndex: 1 } },
        { type: 'heading', data: { text: 'Secure', level: 3, _columnIndex: 1 } },
        {
          type: 'paragraph',
          data: { text: 'Enterprise-grade security out of the box.', _columnIndex: 1 },
        },
        { type: 'icon', data: { icon: 'fa-cogs', size: 'lg', color: '', _columnIndex: 2 } },
        { type: 'heading', data: { text: 'Flexible', level: 3, _columnIndex: 2 } },
        {
          type: 'paragraph',
          data: { text: 'Adapt it to your needs with powerful extensions.', _columnIndex: 2 },
        },
      ],
    },
  ],
});

blockPatternRegistry.register({
  name: 'testimonials-row',
  title: 'Testimonials Row',
  description: 'Three testimonial cards in a row.',
  category: 'Sections',
  icon: '\u275D',
  blocks: [
    { type: 'heading', data: { text: 'What Our Customers Say', level: 2 } },
    { type: 'spacer', data: { height: 16 } },
    {
      type: 'columns',
      data: { columnCount: 3 },
      children: [
        {
          type: 'testimonial',
          data: {
            quote: 'This product transformed how we work. Highly recommended!',
            authorName: 'Jane Smith',
            authorTitle: 'CEO, Acme Corp',
            avatarUrl: '',
            _columnIndex: 0,
          },
        },
        {
          type: 'testimonial',
          data: {
            quote: 'The best tool we have adopted in years. Support is fantastic.',
            authorName: 'John Doe',
            authorTitle: 'CTO, TechStart',
            avatarUrl: '',
            _columnIndex: 1,
          },
        },
        {
          type: 'testimonial',
          data: {
            quote: 'Simple, powerful, and reliable. Everything we needed.',
            authorName: 'Alice Johnson',
            authorTitle: 'VP Engineering, BigCo',
            avatarUrl: '',
            _columnIndex: 2,
          },
        },
      ],
    },
  ],
});

blockPatternRegistry.register({
  name: 'pricing-page',
  title: 'Pricing Page',
  description: 'A heading with three pricing table cards.',
  category: 'Commerce',
  icon: '\u0024',
  blocks: [
    { type: 'heading', data: { text: 'Pricing Plans', level: 2 } },
    {
      type: 'paragraph',
      data: {
        text: 'Choose the plan that fits your needs. All plans include a 14-day free trial.',
        alignment: 'center',
      },
    },
    { type: 'spacer', data: { height: 16 } },
    {
      type: 'columns',
      data: { columnCount: 3 },
      children: [
        {
          type: 'pricing-table',
          data: {
            plans: [
              {
                name: 'Starter',
                price: '$9/mo',
                features: ['5 users', '10 GB storage', 'Email support'],
                cta: 'Start Free Trial',
                highlighted: false,
              },
            ],
            _columnIndex: 0,
          },
        },
        {
          type: 'pricing-table',
          data: {
            plans: [
              {
                name: 'Professional',
                price: '$29/mo',
                features: ['25 users', '100 GB storage', 'Priority support', 'API access'],
                cta: 'Start Free Trial',
                highlighted: true,
              },
            ],
            _columnIndex: 1,
          },
        },
        {
          type: 'pricing-table',
          data: {
            plans: [
              {
                name: 'Enterprise',
                price: 'Custom',
                features: [
                  'Unlimited users',
                  'Unlimited storage',
                  'Dedicated support',
                  'SLA',
                  'Custom integrations',
                ],
                cta: 'Contact Sales',
                highlighted: false,
              },
            ],
            _columnIndex: 2,
          },
        },
      ],
    },
  ],
});

blockPatternRegistry.register({
  name: 'contact-section',
  title: 'Contact Section',
  description: 'A heading, description text, contact form, and map.',
  category: 'Sections',
  icon: '\u2709',
  blocks: [
    { type: 'heading', data: { text: 'Get in Touch', level: 2 } },
    {
      type: 'paragraph',
      data: {
        text: 'Have questions? We would love to hear from you. Send us a message and we will respond as soon as possible.',
      },
    },
    { type: 'spacer', data: { height: 16 } },
    {
      type: 'columns',
      data: { columnCount: 2 },
      children: [
        { type: 'contact-form', data: { _columnIndex: 0 } },
        {
          type: 'map',
          data: { address: '123 Main St, City, Country', zoom: 14, height: 400, _columnIndex: 1 },
        },
      ],
    },
  ],
});

blockPatternRegistry.register({
  name: 'faq-section',
  title: 'FAQ Section',
  description: 'A heading followed by expandable FAQ items.',
  category: 'Sections',
  icon: '?',
  blocks: [
    { type: 'heading', data: { text: 'Frequently Asked Questions', level: 2 } },
    { type: 'spacer', data: { height: 12 } },
    {
      type: 'accordion',
      data: {
        items: [
          {
            title: 'What is your refund policy?',
            content: 'We offer a full refund within 30 days of purchase.',
          },
          {
            title: 'How do I get started?',
            content: 'Sign up for a free trial and follow our getting started guide.',
          },
          {
            title: 'Do you offer support?',
            content:
              'Yes, we offer email support for all plans and priority support for Professional and Enterprise.',
          },
          {
            title: 'Can I cancel anytime?',
            content:
              'Absolutely. You can cancel your subscription at any time from your account settings.',
          },
        ],
        allowMultiple: true,
      },
    },
  ],
});

/**
 * Pattern inserter panel component.
 *
 * Shows a searchable, categorized grid of block patterns with descriptions.
 * One-click inserts all blocks from the pattern.
 */
export class PatternInserterPanel {
  private panelEl: HTMLElement | null = null;
  private readonly onInsertPattern: (blocks: PatternBlockDefinition[]) => void;
  private readonly onClose: () => void;

  constructor(onInsertPattern: (blocks: PatternBlockDefinition[]) => void, onClose: () => void) {
    this.onInsertPattern = onInsertPattern;
    this.onClose = onClose;
  }

  show(container: HTMLElement): void {
    this.hide();

    const panel = document.createElement('div');
    panel.className = 'pb-pattern-inserter';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'Insert block pattern');

    // Search bar
    const searchWrap = document.createElement('div');
    searchWrap.className = 'pb-pattern-inserter__search';

    const searchInput = document.createElement('input');
    searchInput.type = 'search';
    searchInput.className = 'cms-input pb-pattern-inserter__search-input';
    searchInput.placeholder = 'Search patterns...';
    searchInput.setAttribute('aria-label', 'Search block patterns');

    searchInput.addEventListener('input', () => {
      this.filterPatterns(panel, searchInput.value);
    });

    searchWrap.appendChild(searchInput);
    panel.appendChild(searchWrap);

    // Categories
    const categories = blockPatternRegistry.getCategories();
    const patternsContainer = document.createElement('div');
    patternsContainer.className = 'pb-pattern-inserter__content';

    for (const category of categories) {
      const patterns = blockPatternRegistry.getByCategory(category);
      if (patterns.length === 0) continue;

      const section = document.createElement('div');
      section.className = 'pb-pattern-inserter__section';
      section.dataset['category'] = category;

      const heading = document.createElement('h4');
      heading.className = 'pb-pattern-inserter__heading';
      heading.textContent = category;
      section.appendChild(heading);

      const grid = document.createElement('div');
      grid.className = 'pb-pattern-inserter__grid';

      for (const pattern of patterns) {
        const card = document.createElement('button');
        card.type = 'button';
        card.className = 'pb-pattern-inserter__card';
        card.dataset['patternName'] = pattern.name;
        card.dataset['searchText'] =
          `${pattern.title} ${pattern.description} ${pattern.category}`.toLowerCase();

        const iconEl = document.createElement('span');
        iconEl.className = 'pb-pattern-inserter__icon';
        iconEl.textContent = pattern.icon;
        card.appendChild(iconEl);

        const infoEl = document.createElement('div');
        infoEl.className = 'pb-pattern-inserter__info';

        const titleEl = document.createElement('div');
        titleEl.className = 'pb-pattern-inserter__title';
        titleEl.textContent = pattern.title;
        infoEl.appendChild(titleEl);

        const descEl = document.createElement('div');
        descEl.className = 'pb-pattern-inserter__desc';
        descEl.textContent = pattern.description;
        infoEl.appendChild(descEl);

        card.appendChild(infoEl);

        card.addEventListener('click', () => {
          // Deep clone the blocks so each insertion is independent
          const clonedBlocks = structuredClone(pattern.blocks) as PatternBlockDefinition[];
          this.onInsertPattern(clonedBlocks);
          this.hide();
        });

        grid.appendChild(card);
      }

      section.appendChild(grid);
      patternsContainer.appendChild(section);
    }

    panel.appendChild(patternsContainer);

    // Close button
    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'pb-pattern-inserter__close';
    closeBtn.textContent = 'Close';
    closeBtn.setAttribute('aria-label', 'Close pattern inserter');
    closeBtn.addEventListener('click', () => {
      this.hide();
      this.onClose();
    });
    panel.appendChild(closeBtn);

    // Close on Escape
    panel.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        this.hide();
        this.onClose();
      }
    });

    container.appendChild(panel);
    this.panelEl = panel;
    searchInput.focus();
  }

  hide(): void {
    this.panelEl?.remove();
    this.panelEl = null;
  }

  destroy(): void {
    this.hide();
  }

  private filterPatterns(panel: HTMLElement, query: string): void {
    const normalized = query.toLowerCase().trim();
    const cards = panel.querySelectorAll<HTMLElement>('.pb-pattern-inserter__card');

    for (const card of cards) {
      const searchText = card.dataset['searchText'] ?? '';
      card.style.display = normalized === '' || searchText.includes(normalized) ? '' : 'none';
    }

    // Hide empty sections
    const sections = panel.querySelectorAll<HTMLElement>('.pb-pattern-inserter__section');
    for (const section of sections) {
      const visibleCards = section.querySelectorAll<HTMLElement>(
        '.pb-pattern-inserter__card:not([style*="display: none"])',
      );
      section.style.display = visibleCards.length > 0 ? '' : 'none';
    }
  }
}
