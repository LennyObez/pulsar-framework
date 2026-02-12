/**
 * Observable store managing the page builder block tree.
 *
 * Serializes to the ContentBlock JSON format used by the PHP backend:
 * `{ type: string, sort_order: number, data: Record<string, unknown> }`
 *
 * Supports nested blocks for container types (Columns).
 */

export interface BlockData {
  id: string;
  type: string;
  data: Record<string, unknown>;
  children?: BlockData[];
}

interface SerializedBlock {
  type: string;
  sort_order: number;
  data: Record<string, unknown>;
}

export class PageBuilderStore {
  private blocks: BlockData[] = [];
  private listeners: Set<() => void> = new Set();

  subscribe(listener: () => void): () => void {
    this.listeners.add(listener);
    return () => {
      this.listeners.delete(listener);
    };
  }

  getBlocks(): BlockData[] {
    return [...this.blocks];
  }

  addBlock(type: string, data: Record<string, unknown>, index?: number): string {
    const id = this.generateId();
    const block: BlockData = { id, type, data };

    if (type === 'columns' && !block.children) {
      block.children = [];
    }

    if (index !== undefined && index >= 0 && index <= this.blocks.length) {
      this.blocks.splice(index, 0, block);
    } else {
      this.blocks.push(block);
    }

    this.notify();
    return id;
  }

  updateBlock(id: string, data: Partial<Record<string, unknown>>): void {
    const block = this.findBlock(id);
    if (!block) {
      return;
    }

    Object.assign(block.data, data);
    this.notify();
  }

  removeBlock(id: string): void {
    const removed = this.removeFromList(this.blocks, id);
    if (removed) {
      this.notify();
    }
  }

  moveBlock(id: string, toIndex: number): void {
    const block = this.extractBlock(this.blocks, id);
    if (!block) {
      return;
    }

    const clamped = Math.max(0, Math.min(toIndex, this.blocks.length));
    this.blocks.splice(clamped, 0, block);
    this.notify();
  }

  moveBlockToContainer(id: string, containerId: string, index: number): void {
    const block = this.extractBlock(this.blocks, id);
    if (!block) {
      return;
    }

    const container = this.findBlock(containerId);
    if (!container) {
      // Put it back at the end if container not found
      this.blocks.push(block);
      this.notify();
      return;
    }

    if (!container.children) {
      container.children = [];
    }

    const clamped = Math.max(0, Math.min(index, container.children.length));
    container.children.splice(clamped, 0, block);
    this.notify();
  }

  findBlock(id: string): BlockData | undefined {
    return this.findInList(this.blocks, id);
  }

  toJSON(): string {
    return JSON.stringify(this.serializeBlocks(this.blocks));
  }

  fromJSON(json: string): void {
    try {
      const parsed: unknown = JSON.parse(json);
      if (!Array.isArray(parsed)) {
        return;
      }

      this.blocks = this.deserializeBlocks(parsed);
      this.notify();
    } catch {
      // Invalid JSON — leave state unchanged
    }
  }

  private serializeBlocks(blocks: BlockData[]): SerializedBlock[] {
    return blocks.map((block, index) => {
      const serialized: SerializedBlock = {
        type: block.type,
        sort_order: index,
        data: { ...block.data },
      };

      if (block.children && block.children.length > 0) {
        if (block.type === 'columns') {
          // Columns block: serialize children into column structure
          serialized.data['columns'] = this.serializeColumnsChildren(block.children);
        }
      }

      return serialized;
    });
  }

  private serializeColumnsChildren(children: BlockData[]): Array<{ blocks: SerializedBlock[] }> {
    // Group children by column index stored in data._columnIndex
    const columnMap = new Map<number, BlockData[]>();

    for (const child of children) {
      const colIndex =
        typeof child.data['_columnIndex'] === 'number' ? (child.data['_columnIndex'] as number) : 0;
      const existing = columnMap.get(colIndex);
      if (existing) {
        existing.push(child);
      } else {
        columnMap.set(colIndex, [child]);
      }
    }

    const result: Array<{ blocks: SerializedBlock[] }> = [];
    const maxCol = Math.max(0, ...columnMap.keys());

    for (let i = 0; i <= maxCol; i++) {
      const colBlocks = columnMap.get(i) ?? [];
      result.push({
        blocks: colBlocks.map((b, sortIndex) => ({
          type: b.type,
          sort_order: sortIndex,
          data: { ...b.data },
        })),
      });
    }

    return result;
  }

  private deserializeBlocks(raw: unknown[]): BlockData[] {
    const blocks: BlockData[] = [];

    for (const item of raw) {
      if (typeof item !== 'object' || item === null) {
        continue;
      }

      const record = item as Record<string, unknown>;
      const type = typeof record['type'] === 'string' ? record['type'] : 'text';
      const data =
        typeof record['data'] === 'object' &&
        record['data'] !== null &&
        !Array.isArray(record['data'])
          ? { ...(record['data'] as Record<string, unknown>) }
          : {};

      const block: BlockData = {
        id: this.generateId(),
        type,
        data,
      };

      // Reconstruct children for columns blocks
      if (type === 'columns' && Array.isArray(data['columns'])) {
        block.children = [];
        const columns = data['columns'] as Array<unknown>;

        for (let colIndex = 0; colIndex < columns.length; colIndex++) {
          const col = columns[colIndex];
          if (typeof col !== 'object' || col === null) {
            continue;
          }

          const colRecord = col as Record<string, unknown>;
          const colBlocks = Array.isArray(colRecord['blocks']) ? colRecord['blocks'] : [];

          for (const colBlock of colBlocks) {
            if (typeof colBlock !== 'object' || colBlock === null) {
              continue;
            }

            const cb = colBlock as Record<string, unknown>;
            const childData =
              typeof cb['data'] === 'object' && cb['data'] !== null && !Array.isArray(cb['data'])
                ? { ...(cb['data'] as Record<string, unknown>) }
                : {};
            childData['_columnIndex'] = colIndex;

            block.children.push({
              id: this.generateId(),
              type:
                typeof cb['blockType'] === 'string'
                  ? cb['blockType']
                  : typeof cb['type'] === 'string'
                    ? cb['type']
                    : 'text',
              data: childData,
            });
          }
        }

        // Remove columns from data since it's represented as children
        delete data['columns'];
      }

      blocks.push(block);
    }

    return blocks;
  }

  private findInList(blocks: BlockData[], id: string): BlockData | undefined {
    for (const block of blocks) {
      if (block.id === id) {
        return block;
      }

      if (block.children) {
        const found = this.findInList(block.children, id);
        if (found) {
          return found;
        }
      }
    }

    return undefined;
  }

  private removeFromList(blocks: BlockData[], id: string): boolean {
    for (let i = 0; i < blocks.length; i++) {
      const block = blocks[i];
      if (!block) continue;

      if (block.id === id) {
        blocks.splice(i, 1);
        return true;
      }

      if (block.children && this.removeFromList(block.children, id)) {
        return true;
      }
    }

    return false;
  }

  private extractBlock(blocks: BlockData[], id: string): BlockData | undefined {
    for (let i = 0; i < blocks.length; i++) {
      const block = blocks[i];
      if (!block) continue;

      if (block.id === id) {
        return blocks.splice(i, 1)[0];
      }

      if (block.children) {
        const extracted = this.extractBlock(block.children, id);
        if (extracted) {
          return extracted;
        }
      }
    }

    return undefined;
  }

  private notify(): void {
    for (const listener of this.listeners) {
      listener();
    }
  }

  private generateId(): string {
    return crypto.randomUUID();
  }
}
