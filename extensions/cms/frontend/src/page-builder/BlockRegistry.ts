/**
 * Frontend block type registry for the page builder.
 *
 * Maps block types to their Custom Element tag names, labels, icons, and
 * categories. Used by the block inserter panel to display available blocks.
 */

export interface BlockRegistration {
  type: string;
  label: string;
  icon: string;
  category: 'text' | 'media' | 'layout' | 'interactive' | 'data' | 'advanced';
  element: string;
}

export class BlockRegistry {
  private blocks: Map<string, BlockRegistration> = new Map();

  register(registration: BlockRegistration): void {
    this.blocks.set(registration.type, registration);
  }

  get(type: string): BlockRegistration | undefined {
    return this.blocks.get(type);
  }

  getByCategory(category: string): BlockRegistration[] {
    const result: BlockRegistration[] = [];
    for (const registration of this.blocks.values()) {
      if (registration.category === category) {
        result.push(registration);
      }
    }
    return result;
  }

  all(): BlockRegistration[] {
    return [...this.blocks.values()];
  }
}

/**
 * Shared singleton registry instance pre-populated with core blocks.
 */
export const blockRegistry = new BlockRegistry();

blockRegistry.register({
  type: 'paragraph',
  label: 'Paragraph',
  icon: 'fa-paragraph',
  category: 'text',
  element: 'cms-pb-paragraph',
});

blockRegistry.register({
  type: 'heading',
  label: 'Heading',
  icon: 'fa-heading',
  category: 'text',
  element: 'cms-pb-heading',
});

blockRegistry.register({
  type: 'image',
  label: 'Image',
  icon: 'fa-image',
  category: 'media',
  element: 'cms-pb-image',
});

blockRegistry.register({
  type: 'gallery',
  label: 'Gallery',
  icon: 'fa-images',
  category: 'media',
  element: 'cms-pb-gallery',
});

blockRegistry.register({
  type: 'columns',
  label: 'Columns',
  icon: 'fa-columns',
  category: 'layout',
  element: 'cms-pb-columns',
});

blockRegistry.register({
  type: 'button',
  label: 'Button',
  icon: 'fa-square',
  category: 'interactive',
  element: 'cms-pb-button',
});

blockRegistry.register({
  type: 'video',
  label: 'Video',
  icon: 'fa-video',
  category: 'media',
  element: 'cms-pb-video',
});

// --- Text blocks ---

blockRegistry.register({
  type: 'quote',
  label: 'Quote',
  icon: 'fa-quote-left',
  category: 'text',
  element: 'cms-pb-quote',
});

blockRegistry.register({
  type: 'list',
  label: 'List',
  icon: 'fa-list',
  category: 'text',
  element: 'cms-pb-list',
});

blockRegistry.register({
  type: 'code',
  label: 'Code',
  icon: 'fa-code',
  category: 'text',
  element: 'cms-pb-code',
});

blockRegistry.register({
  type: 'html',
  label: 'HTML',
  icon: 'fa-file-code',
  category: 'text',
  element: 'cms-pb-html',
});

// --- Media blocks ---

blockRegistry.register({
  type: 'audio',
  label: 'Audio',
  icon: 'fa-music',
  category: 'media',
  element: 'cms-pb-audio',
});

blockRegistry.register({
  type: 'embed',
  label: 'Embed',
  icon: 'fa-code',
  category: 'media',
  element: 'cms-pb-embed',
});

blockRegistry.register({
  type: 'file-download',
  label: 'File Download',
  icon: 'fa-download',
  category: 'media',
  element: 'cms-pb-file-download',
});

blockRegistry.register({
  type: 'icon',
  label: 'Icon',
  icon: 'fa-star',
  category: 'media',
  element: 'cms-pb-icon',
});

// --- Layout blocks ---

blockRegistry.register({
  type: 'spacer',
  label: 'Spacer',
  icon: 'fa-arrows-alt-v',
  category: 'layout',
  element: 'cms-pb-spacer',
});

blockRegistry.register({
  type: 'separator',
  label: 'Separator',
  icon: 'fa-minus',
  category: 'layout',
  element: 'cms-pb-separator',
});

blockRegistry.register({
  type: 'carousel',
  label: 'Carousel',
  icon: 'fa-images',
  category: 'layout',
  element: 'cms-pb-carousel',
});

blockRegistry.register({
  type: 'accordion',
  label: 'Accordion',
  icon: 'fa-bars',
  category: 'layout',
  element: 'cms-pb-accordion',
});

blockRegistry.register({
  type: 'tabs',
  label: 'Tabs',
  icon: 'fa-folder',
  category: 'layout',
  element: 'cms-pb-tabs',
});

// --- Interactive blocks ---

blockRegistry.register({
  type: 'button-group',
  label: 'Button Group',
  icon: 'fa-th-large',
  category: 'interactive',
  element: 'cms-pb-button-group',
});

blockRegistry.register({
  type: 'contact-form',
  label: 'Contact Form',
  icon: 'fa-envelope',
  category: 'interactive',
  element: 'cms-pb-contact-form',
});

blockRegistry.register({
  type: 'map',
  label: 'Map',
  icon: 'fa-map-marker-alt',
  category: 'interactive',
  element: 'cms-pb-map',
});

blockRegistry.register({
  type: 'counter',
  label: 'Counter',
  icon: 'fa-sort-numeric-up',
  category: 'interactive',
  element: 'cms-pb-counter',
});

blockRegistry.register({
  type: 'progress-bar',
  label: 'Progress Bar',
  icon: 'fa-tasks',
  category: 'interactive',
  element: 'cms-pb-progress',
});

// --- Data blocks ---

blockRegistry.register({
  type: 'table',
  label: 'Table',
  icon: 'fa-table',
  category: 'data',
  element: 'cms-pb-table',
});

blockRegistry.register({
  type: 'pricing-table',
  label: 'Pricing Table',
  icon: 'fa-tag',
  category: 'data',
  element: 'cms-pb-pricing',
});

// --- Advanced blocks ---

blockRegistry.register({
  type: 'alert',
  label: 'Alert',
  icon: 'fa-exclamation-triangle',
  category: 'advanced',
  element: 'cms-pb-alert',
});

blockRegistry.register({
  type: 'hero',
  label: 'Hero',
  icon: 'fa-image',
  category: 'advanced',
  element: 'cms-pb-hero',
});

blockRegistry.register({
  type: 'cta',
  label: 'Call to Action',
  icon: 'fa-bullhorn',
  category: 'advanced',
  element: 'cms-pb-cta',
});

blockRegistry.register({
  type: 'testimonial',
  label: 'Testimonial',
  icon: 'fa-comment',
  category: 'advanced',
  element: 'cms-pb-testimonial',
});

blockRegistry.register({
  type: 'social-links',
  label: 'Social Links',
  icon: 'fa-share-alt',
  category: 'advanced',
  element: 'cms-pb-social-links',
});
