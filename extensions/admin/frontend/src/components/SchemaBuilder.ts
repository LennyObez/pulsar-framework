/**
 * Schema Builder component — Create Table form.
 *
 * Mounts on [data-schema-builder] containers. Provides a dynamic form
 * for creating database tables with live SQL preview.
 */
import { api } from '../api.js';
import type {
  SchemaCapabilities,
  SchemaColumnDef,
  SchemaForeignKeyDef,
  SchemaIndexDef,
  SchemaPreviewResult,
  SchemaReferentialAction,
  TableStructure,
} from '../types.js';

const COLUMN_TYPES = [
  'string',
  'text',
  'integer',
  'smallint',
  'bigint',
  'float',
  'decimal',
  'boolean',
  'datetime',
  'date',
  'time',
  'json',
  'uuid',
  'binary',
  'enum',
] as const;

const DEFAULT_EXPRESSIONS = [
  '',
  'CURRENT_TIMESTAMP',
  'CURRENT_DATE',
  'CURRENT_TIME',
  'TRUE',
  'FALSE',
  'NULL',
] as const;

const REFERENTIAL_ACTIONS: SchemaReferentialAction[] = [
  'RESTRICT',
  'CASCADE',
  'SET NULL',
  'NO ACTION',
];

interface ColumnRow {
  name: string;
  type: string;
  nullable: boolean;
  primaryKey: boolean;
  autoIncrement: boolean;
  unsigned: boolean;
  unique: boolean;
  hasDefault: boolean;
  defaultValue: string;
  defaultExpression: string;
  length: string;
  precision: string;
  scale: string;
  enumValues: string;
}

interface IndexRow {
  name: string;
  columns: string;
  unique: boolean;
}

interface ForeignKeyRow {
  name: string;
  columns: string;
  referencedTable: string;
  referencedColumns: string;
  onDelete: SchemaReferentialAction;
  onUpdate: SchemaReferentialAction;
}

export function initializeSchemaBuilder(): void {
  const container = document.querySelector<HTMLElement>('[data-schema-builder]');
  if (!container) return;

  const capabilities: SchemaCapabilities = JSON.parse(
    container.dataset.capabilities ?? '{}',
  ) as SchemaCapabilities;
  const driver = container.dataset.driver ?? 'sqlite';
  const existingTables: string[] = JSON.parse(container.dataset.tables ?? '[]') as string[];

  const root: HTMLElement = container;
  let tableName = '';
  const columns: ColumnRow[] = [createDefaultColumn()];
  const indexes: IndexRow[] = [];
  const foreignKeys: ForeignKeyRow[] = [];
  let reason = '';
  let previewResult: SchemaPreviewResult | null = null;
  let previewTimeout: ReturnType<typeof setTimeout> | null = null;
  let submitting = false;

  render();

  function createDefaultColumn(): ColumnRow {
    return {
      name: '',
      type: 'string',
      nullable: false,
      primaryKey: false,
      autoIncrement: false,
      unsigned: false,
      unique: false,
      hasDefault: false,
      defaultValue: '',
      defaultExpression: '',
      length: '',
      precision: '',
      scale: '',
      enumValues: '',
    };
  }

  function buildTableStructure(): TableStructure {
    return {
      name: tableName,
      columns: columns
        .filter((c) => c.name.trim() !== '')
        .map((c): SchemaColumnDef => ({
          name: c.name,
          type: c.type,
          nullable: c.nullable,
          primaryKey: c.primaryKey,
          autoIncrement: c.autoIncrement,
          unsigned: c.unsigned,
          unique: c.unique,
          hasDefault: c.hasDefault,
          defaultValue: c.hasDefault && c.defaultExpression === '' ? c.defaultValue : null,
          defaultExpression: c.defaultExpression || null,
          length: c.length ? parseInt(c.length, 10) : null,
          precision: c.precision ? parseInt(c.precision, 10) : null,
          scale: c.scale ? parseInt(c.scale, 10) : null,
          enumValues: c.type === 'enum' ? c.enumValues.split(',').map((v) => v.trim()) : [],
        })),
      indexes: indexes
        .filter((i) => i.name.trim() !== '' && i.columns.trim() !== '')
        .map((i): SchemaIndexDef => ({
          name: i.name,
          columns: i.columns.split(',').map((c) => c.trim()),
          unique: i.unique,
        })),
      foreignKeys: foreignKeys
        .filter((fk) => fk.name.trim() !== '' && fk.columns.trim() !== '')
        .map((fk): SchemaForeignKeyDef => ({
          name: fk.name,
          columns: fk.columns.split(',').map((c) => c.trim()),
          referencedTable: fk.referencedTable,
          referencedColumns: fk.referencedColumns.split(',').map((c) => c.trim()),
          onDelete: fk.onDelete,
          onUpdate: fk.onUpdate,
        })),
    };
  }

  function schedulePreview(): void {
    if (previewTimeout) clearTimeout(previewTimeout);
    previewTimeout = setTimeout(async () => {
      const def = buildTableStructure();
      if (def.name && def.columns.length > 0) {
        try {
          previewResult = await api.schemaPreviewCreate(def);
          render();
        } catch {
          // Preview failed silently
        }
      }
    }, 500);
  }

  async function handleSubmit(): Promise<void> {
    if (submitting) return;
    submitting = true;
    render();

    try {
      const def = buildTableStructure();
      const result = await api.schemaCreate(def, reason);
      if (result.success) {
        window.location.href = `/admin/schema/${def.name}`;
      } else {
        alert(result.message);
        submitting = false;
        render();
      }
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Failed to create table');
      submitting = false;
      render();
    }
  }

  function render(): void {
    const e = (s: string): string =>
      s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

    root.innerHTML = `
      <form class="admin-schema-builder" data-schema-form>
        <section class="admin-schema-builder__section">
          <h2>Table Name</h2>
          <input type="text" class="admin-input" data-field="table-name" value="${e(tableName)}" placeholder="my_table" required>
        </section>

        <section class="admin-schema-builder__section">
          <h2>Columns</h2>
          <div class="admin-schema-builder__columns">
            ${columns.map((col, i) => renderColumnRow(col, i, e)).join('')}
          </div>
          <button type="button" class="admin-btn admin-btn--sm" data-add-column>+ Add Column</button>
        </section>

        <section class="admin-schema-builder__section">
          <h2>Indexes</h2>
          <div class="admin-schema-builder__indexes">
            ${indexes.map((idx, i) => renderIndexRow(idx, i, e)).join('')}
          </div>
          <button type="button" class="admin-btn admin-btn--sm" data-add-index>+ Add Index</button>
        </section>

        ${
          capabilities.supportsForeignKeys
            ? `
        <section class="admin-schema-builder__section">
          <h2>Foreign Keys</h2>
          <div class="admin-schema-builder__fks">
            ${foreignKeys.map((fk, i) => renderForeignKeyRow(fk, i, e, existingTables)).join('')}
          </div>
          <button type="button" class="admin-btn admin-btn--sm" data-add-fk>+ Add Foreign Key</button>
        </section>
        `
            : ''
        }

        <section class="admin-schema-builder__section">
          <h2>SQL Preview</h2>
          <div class="admin-schema-builder__preview">
            ${
              previewResult
                ? `
              <pre class="admin-code"><code>${previewResult.statements.map((s) => e(s) + ';').join('\n')}</code></pre>
              ${previewResult.warnings.length > 0 ? `<div class="admin-alert admin-alert--warning">${previewResult.warnings.map((w) => e(w.message)).join('<br>')}</div>` : ''}
            `
                : '<p class="admin-field--null">Fill in the form to see SQL preview</p>'
            }
          </div>
        </section>

        <section class="admin-schema-builder__section">
          <h2>Reason <span class="admin-field--required">*</span></h2>
          <textarea class="admin-input" data-field="reason" rows="2" required minlength="5" placeholder="Why are you creating this table? (min 5 chars)">${e(reason)}</textarea>
        </section>

        <div class="admin-schema-builder__actions">
          <button type="submit" class="admin-btn admin-btn--primary" ${submitting ? 'disabled' : ''}>
            ${submitting ? 'Creating...' : 'Create Table'}
          </button>
          <a href="/admin/schema" class="admin-btn admin-btn--secondary">Cancel</a>
        </div>
      </form>
    `;

    bindEvents();
  }

  function renderColumnRow(col: ColumnRow, i: number, e: (s: string) => string): string {
    const showLength = col.type === 'string' || col.type === 'enum';
    const showPrecision = col.type === 'decimal';
    const showEnumValues = col.type === 'enum';

    return `
      <div class="admin-schema-builder__column-row" data-column-index="${i}">
        <input type="text" placeholder="Column name" value="${e(col.name)}" data-col-field="name" data-col-index="${i}" class="admin-input admin-input--sm">
        <select data-col-field="type" data-col-index="${i}" class="admin-input admin-input--sm">
          ${COLUMN_TYPES.map((t) => `<option value="${t}" ${t === col.type ? 'selected' : ''}>${t}</option>`).join('')}
        </select>
        <label><input type="checkbox" data-col-field="nullable" data-col-index="${i}" ${col.nullable ? 'checked' : ''}> Null</label>
        <label><input type="checkbox" data-col-field="primaryKey" data-col-index="${i}" ${col.primaryKey ? 'checked' : ''}> PK</label>
        <label><input type="checkbox" data-col-field="autoIncrement" data-col-index="${i}" ${col.autoIncrement ? 'checked' : ''}> Auto</label>
        <label><input type="checkbox" data-col-field="unique" data-col-index="${i}" ${col.unique ? 'checked' : ''}> Unique</label>
        ${driver === 'mysql' ? `<label><input type="checkbox" data-col-field="unsigned" data-col-index="${i}" ${col.unsigned ? 'checked' : ''}> Unsigned</label>` : ''}
        ${showLength ? `<input type="number" placeholder="Length" value="${e(col.length)}" data-col-field="length" data-col-index="${i}" class="admin-input admin-input--xs">` : ''}
        ${showPrecision ? `<input type="number" placeholder="Precision" value="${e(col.precision)}" data-col-field="precision" data-col-index="${i}" class="admin-input admin-input--xs"><input type="number" placeholder="Scale" value="${e(col.scale)}" data-col-field="scale" data-col-index="${i}" class="admin-input admin-input--xs">` : ''}
        ${showEnumValues ? `<input type="text" placeholder="val1, val2, val3" value="${e(col.enumValues)}" data-col-field="enumValues" data-col-index="${i}" class="admin-input admin-input--sm">` : ''}
        <select data-col-field="defaultExpression" data-col-index="${i}" class="admin-input admin-input--sm">
          ${DEFAULT_EXPRESSIONS.map((expr) => `<option value="${expr}" ${expr === col.defaultExpression ? 'selected' : ''}>${expr || '(no expression)'}</option>`).join('')}
        </select>
        <button type="button" class="admin-btn admin-btn--sm admin-btn--danger" data-remove-column="${i}">&times;</button>
      </div>
    `;
  }

  function renderIndexRow(idx: IndexRow, i: number, e: (s: string) => string): string {
    return `
      <div class="admin-schema-builder__index-row" data-index-row="${i}">
        <input type="text" placeholder="Index name" value="${e(idx.name)}" data-idx-field="name" data-idx-index="${i}" class="admin-input admin-input--sm">
        <input type="text" placeholder="col1, col2" value="${e(idx.columns)}" data-idx-field="columns" data-idx-index="${i}" class="admin-input admin-input--sm">
        <label><input type="checkbox" data-idx-field="unique" data-idx-index="${i}" ${idx.unique ? 'checked' : ''}> Unique</label>
        <button type="button" class="admin-btn admin-btn--sm admin-btn--danger" data-remove-index="${i}">&times;</button>
      </div>
    `;
  }

  function renderForeignKeyRow(
    fk: ForeignKeyRow,
    i: number,
    e: (s: string) => string,
    tables: string[],
  ): string {
    return `
      <div class="admin-schema-builder__fk-row" data-fk-row="${i}">
        <input type="text" placeholder="FK name" value="${e(fk.name)}" data-fk-field="name" data-fk-index="${i}" class="admin-input admin-input--sm">
        <input type="text" placeholder="Local cols" value="${e(fk.columns)}" data-fk-field="columns" data-fk-index="${i}" class="admin-input admin-input--sm">
        <select data-fk-field="referencedTable" data-fk-index="${i}" class="admin-input admin-input--sm">
          <option value="">-- table --</option>
          ${tables.map((t) => `<option value="${e(t)}" ${t === fk.referencedTable ? 'selected' : ''}>${e(t)}</option>`).join('')}
        </select>
        <input type="text" placeholder="Ref cols" value="${e(fk.referencedColumns)}" data-fk-field="referencedColumns" data-fk-index="${i}" class="admin-input admin-input--sm">
        <select data-fk-field="onDelete" data-fk-index="${i}" class="admin-input admin-input--sm">
          ${REFERENTIAL_ACTIONS.map((a) => `<option value="${a}" ${a === fk.onDelete ? 'selected' : ''}>${a}</option>`).join('')}
        </select>
        <select data-fk-field="onUpdate" data-fk-index="${i}" class="admin-input admin-input--sm">
          ${REFERENTIAL_ACTIONS.map((a) => `<option value="${a}" ${a === fk.onUpdate ? 'selected' : ''}>${a}</option>`).join('')}
        </select>
        <button type="button" class="admin-btn admin-btn--sm admin-btn--danger" data-remove-fk="${i}">&times;</button>
      </div>
    `;
  }

  function setRowField(row: object, field: string, value: unknown): void {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any -- dynamic field setter for form binding
    (row as any)[field] = value;
  }

  function bindEvents(): void {
    const form = root.querySelector('[data-schema-form]');

    form?.addEventListener('submit', (ev) => {
      ev.preventDefault();
      void handleSubmit();
    });

    root.querySelector('[data-field="table-name"]')?.addEventListener('input', (ev) => {
      tableName = (ev.target as HTMLInputElement).value;
      schedulePreview();
    });

    root.querySelector('[data-field="reason"]')?.addEventListener('input', (ev) => {
      reason = (ev.target as HTMLTextAreaElement).value;
    });

    root.querySelector('[data-add-column]')?.addEventListener('click', () => {
      columns.push(createDefaultColumn());
      render();
    });

    root.querySelector('[data-add-index]')?.addEventListener('click', () => {
      indexes.push({ name: '', columns: '', unique: false });
      render();
    });

    root.querySelector('[data-add-fk]')?.addEventListener('click', () => {
      foreignKeys.push({
        name: '',
        columns: '',
        referencedTable: '',
        referencedColumns: '',
        onDelete: 'RESTRICT',
        onUpdate: 'RESTRICT',
      });
      render();
    });

    root.querySelectorAll('[data-remove-column]').forEach((btn) => {
      btn.addEventListener('click', () => {
        columns.splice(parseInt((btn as HTMLElement).dataset.removeColumn!, 10), 1);
        render();
        schedulePreview();
      });
    });

    root.querySelectorAll('[data-remove-index]').forEach((btn) => {
      btn.addEventListener('click', () => {
        indexes.splice(parseInt((btn as HTMLElement).dataset.removeIndex!, 10), 1);
        render();
        schedulePreview();
      });
    });

    root.querySelectorAll('[data-remove-fk]').forEach((btn) => {
      btn.addEventListener('click', () => {
        foreignKeys.splice(parseInt((btn as HTMLElement).dataset.removeFk!, 10), 1);
        render();
        schedulePreview();
      });
    });

    // Column field changes
    root.querySelectorAll('[data-col-field]').forEach((el) => {
      const field = (el as HTMLElement).dataset.colField!;
      const idx = parseInt((el as HTMLElement).dataset.colIndex!, 10);

      el.addEventListener('change', () => {
        const row = columns[idx]!;
        if (el instanceof HTMLInputElement && el.type === 'checkbox') {
          setRowField(row, field, el.checked);
        } else {
          setRowField(row, field, (el as HTMLInputElement).value);
        }
        if (field === 'type') render();
        schedulePreview();
      });

      el.addEventListener('input', () => {
        const row = columns[idx]!;
        if (el instanceof HTMLInputElement && el.type !== 'checkbox') {
          setRowField(row, field, el.value);
          schedulePreview();
        }
      });
    });

    // Index field changes
    root.querySelectorAll('[data-idx-field]').forEach((el) => {
      const field = (el as HTMLElement).dataset.idxField!;
      const idx = parseInt((el as HTMLElement).dataset.idxIndex!, 10);

      el.addEventListener('input', () => {
        const row = indexes[idx]!;
        if (el instanceof HTMLInputElement && el.type === 'checkbox') {
          setRowField(row, field, el.checked);
        } else {
          setRowField(row, field, (el as HTMLInputElement).value);
        }
        schedulePreview();
      });
    });

    // FK field changes
    root.querySelectorAll('[data-fk-field]').forEach((el) => {
      const field = (el as HTMLElement).dataset.fkField!;
      const idx = parseInt((el as HTMLElement).dataset.fkIndex!, 10);

      el.addEventListener('input', () => {
        const row = foreignKeys[idx]!;
        setRowField(row, field, (el as HTMLInputElement | HTMLSelectElement).value);
        schedulePreview();
      });

      el.addEventListener('change', () => {
        const row = foreignKeys[idx]!;
        setRowField(row, field, (el as HTMLInputElement | HTMLSelectElement).value);
        schedulePreview();
      });
    });
  }
}
