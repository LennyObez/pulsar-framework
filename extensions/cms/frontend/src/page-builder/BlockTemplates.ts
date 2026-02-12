/**
 * Block template manager for the page builder.
 *
 * Saves and loads configured blocks as reusable templates. Persists
 * templates in localStorage and optionally syncs to the backend API.
 */

import { cmsApi } from '../utils/api.js';
import type { BlockData } from './PageBuilderStore.js';

export interface BlockTemplate {
  id: string;
  name: string;
  description: string;
  blockType: string;
  blockData: Record<string, unknown>;
  children?: Array<{ type: string; data: Record<string, unknown> }>;
  createdAt: string;
}

const STORAGE_KEY = 'cms-pb-block-templates';
const API_BASE = '/admin/api/block-templates';

export class BlockTemplateManager {
  private templates: BlockTemplate[] = [];
  private syncing = false;

  constructor() {
    this.templates = this.readStorage();
  }

  /** Load all templates from localStorage (and optionally sync from API). */
  load(): BlockTemplate[] {
    this.templates = this.readStorage();
    return [...this.templates];
  }

  /** Save a template. Replaces any existing template with the same id. */
  save(template: BlockTemplate): void {
    const index = this.templates.findIndex((t) => t.id === template.id);
    if (index >= 0) {
      this.templates[index] = { ...template };
    } else {
      this.templates.push({ ...template });
    }
    this.writeStorage();
    this.syncToApi('save', template);
  }

  /** Remove a template by id. */
  remove(id: string): void {
    const template = this.templates.find((t) => t.id === id);
    this.templates = this.templates.filter((t) => t.id !== id);
    this.writeStorage();
    if (template) {
      this.syncToApi('remove', template);
    }
  }

  /** Get all templates matching a block type. */
  getByType(blockType: string): BlockTemplate[] {
    return this.templates.filter((t) => t.blockType === blockType);
  }

  /** Return all templates. */
  all(): BlockTemplate[] {
    return [...this.templates];
  }

  /**
   * Instantiate a template into a BlockData structure suitable for
   * insertion into the page builder store.
   */
  instantiate(templateId: string): BlockData | null {
    const template = this.templates.find((t) => t.id === templateId);
    if (!template) return null;

    const block: BlockData = {
      id: crypto.randomUUID(),
      type: template.blockType,
      data: structuredClone(template.blockData),
    };

    if (template.children && template.children.length > 0) {
      block.children = template.children.map((child) => ({
        id: crypto.randomUUID(),
        type: child.type,
        data: structuredClone(child.data),
      }));
    }

    return block;
  }

  private readStorage(): BlockTemplate[] {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return [];

      const parsed: unknown = JSON.parse(raw);
      if (!Array.isArray(parsed)) return [];

      return parsed.filter(
        (item): item is BlockTemplate =>
          typeof item === 'object' &&
          item !== null &&
          typeof (item as Record<string, unknown>).id === 'string' &&
          typeof (item as Record<string, unknown>).name === 'string' &&
          typeof (item as Record<string, unknown>).blockType === 'string' &&
          typeof (item as Record<string, unknown>).blockData === 'object' &&
          (item as Record<string, unknown>).blockData !== null,
      );
    } catch {
      return [];
    }
  }

  private writeStorage(): void {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(this.templates));
    } catch {
      // localStorage full or unavailable
    }
  }

  private syncToApi(action: 'save' | 'remove', template: BlockTemplate): void {
    if (this.syncing) return;
    this.syncing = true;

    const request =
      action === 'save'
        ? cmsApi(API_BASE, {
            method: 'POST',
            body: JSON.stringify(template),
          })
        : cmsApi(`${API_BASE}/${encodeURIComponent(template.id)}`, {
            method: 'DELETE',
          });

    request
      .catch(() => {
        // API sync is best-effort; localStorage is the source of truth
      })
      .finally(() => {
        this.syncing = false;
      });
  }
}

/** Shared singleton instance. */
export const blockTemplates = new BlockTemplateManager();
