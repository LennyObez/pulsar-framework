/**
 * Schema Table View component — view/edit table structure.
 *
 * Mounts on [data-schema-view] containers. Shows columns, indexes,
 * and provides add/drop column, add/drop index, rename/drop table actions.
 */
import { api } from '../api.js';
import type { SchemaCapabilities } from '../types.js';

export function initializeSchemaView(): void {
  const container = document.querySelector<HTMLElement>('[data-schema-view]');
  if (!container) return;

  const table = container.dataset.table ?? '';
  const capabilities: SchemaCapabilities = JSON.parse(
    container.dataset.capabilities ?? '{}',
  ) as SchemaCapabilities;

  // Drop column buttons — disabled when driver doesn't support DROP COLUMN
  container.querySelectorAll<HTMLButtonElement>('[data-drop-column]').forEach((btn) => {
    if (!capabilities.supportsDropColumn) {
      btn.disabled = true;
      btn.title = 'DROP COLUMN is not supported by this database driver';
      return;
    }
    btn.addEventListener('click', () => {
      const column = btn.dataset.dropColumn!;
      const targetTable = btn.dataset.table!;

      const confirmName = prompt(
        `Type the column name "${column}" to confirm dropping it from "${targetTable}":`,
      );
      if (confirmName !== column) {
        if (confirmName !== null) alert('Column name does not match. Drop cancelled.');
        return;
      }

      const reason = prompt('Reason for dropping this column (min 5 chars):');
      if (!reason || reason.length < 5) {
        alert('A reason of at least 5 characters is required.');
        return;
      }

      void api.schemaDropColumn(targetTable, column, reason).then((result) => {
        if (result.success) {
          window.location.reload();
        } else {
          alert(result.message);
        }
      });
    });
  });

  // Add column form
  const addColumnSection = document.createElement('div');
  addColumnSection.className = 'admin-schema-view__add-column';
  addColumnSection.innerHTML = `
    <h3>Add Column</h3>
    <form class="admin-schema-view__add-column-form" data-add-column-form>
      <input type="text" placeholder="Column name" data-ac-name class="admin-input admin-input--sm" required>
      <select data-ac-type class="admin-input admin-input--sm">
        <option value="string">string</option>
        <option value="text">text</option>
        <option value="integer">integer</option>
        <option value="bigint">bigint</option>
        <option value="boolean">boolean</option>
        <option value="datetime">datetime</option>
        <option value="json">json</option>
        <option value="uuid">uuid</option>
      </select>
      <label><input type="checkbox" data-ac-nullable> Nullable</label>
      <input type="text" placeholder="Reason (min 5 chars)" data-ac-reason class="admin-input admin-input--sm" required minlength="5">
      <button type="submit" class="admin-btn admin-btn--sm admin-btn--primary">Add</button>
    </form>
  `;

  const tableEl = container.querySelector('.admin-table');
  if (tableEl) {
    tableEl.after(addColumnSection);
  } else {
    container.appendChild(addColumnSection);
  }

  const addForm = container.querySelector<HTMLFormElement>('[data-add-column-form]');
  addForm?.addEventListener('submit', (ev) => {
    ev.preventDefault();

    const name = (container.querySelector('[data-ac-name]') as HTMLInputElement).value;
    const type = (container.querySelector('[data-ac-type]') as HTMLSelectElement).value;
    const nullable = (container.querySelector('[data-ac-nullable]') as HTMLInputElement).checked;
    const reason = (container.querySelector('[data-ac-reason]') as HTMLInputElement).value;

    void api
      .schemaAddColumn(
        table,
        {
          name,
          type,
          nullable,
          primaryKey: false,
          autoIncrement: false,
          unsigned: false,
          unique: false,
          hasDefault: false,
          defaultValue: null,
          defaultExpression: null,
          length: null,
          precision: null,
          scale: null,
          enumValues: [],
        },
        reason,
      )
      .then((result) => {
        if (result.success) {
          window.location.reload();
        } else {
          alert(result.message);
        }
      });
  });

  // Rename table section
  const renameSection = document.createElement('div');
  renameSection.className = 'admin-schema-view__rename';
  renameSection.innerHTML = `
    <h3>Rename Table</h3>
    <form data-rename-form class="admin-schema-view__rename-form">
      <input type="text" placeholder="New table name" data-rename-name class="admin-input admin-input--sm" required>
      <input type="text" placeholder="Reason (min 5 chars)" data-rename-reason class="admin-input admin-input--sm" required minlength="5">
      <button type="submit" class="admin-btn admin-btn--sm admin-btn--warning">Rename</button>
    </form>
  `;
  container.appendChild(renameSection);

  const renameForm = container.querySelector<HTMLFormElement>('[data-rename-form]');
  renameForm?.addEventListener('submit', (ev) => {
    ev.preventDefault();
    const newName = (container.querySelector('[data-rename-name]') as HTMLInputElement).value;
    const reason = (container.querySelector('[data-rename-reason]') as HTMLInputElement).value;

    const confirmName = prompt(`Type the current table name "${table}" to confirm the rename:`);
    if (confirmName !== table) {
      if (confirmName !== null) alert('Table name does not match. Rename cancelled.');
      return;
    }

    void api.schemaRenameTable(table, newName, reason).then((result) => {
      if (result.success) {
        window.location.href = `/admin/schema/${encodeURIComponent(newName)}`;
      } else {
        alert(result.message);
      }
    });
  });

  // Drop table section (danger zone)
  const dropSection = document.createElement('div');
  dropSection.className = 'admin-schema-view__danger-zone';

  const dangerHeading = document.createElement('h3');
  dangerHeading.textContent = 'Danger Zone';
  dropSection.appendChild(dangerHeading);

  const dangerDesc = document.createElement('p');
  dangerDesc.textContent = 'Dropping a table permanently deletes all its data and structure.';
  dropSection.appendChild(dangerDesc);

  const dropBtn = document.createElement('button');
  dropBtn.type = 'button';
  dropBtn.className = 'admin-btn admin-btn--danger';
  dropBtn.dataset.dropTable = '';
  dropBtn.textContent = `Drop Table "${table}"`;
  dropSection.appendChild(dropBtn);

  container.appendChild(dropSection);

  container.querySelector('[data-drop-table]')?.addEventListener('click', () => {
    const confirmName = prompt(`Type the table name "${table}" to confirm permanent deletion:`);
    if (confirmName !== table) {
      if (confirmName !== null) alert('Table name does not match. Drop cancelled.');
      return;
    }

    const reason = prompt('Reason for dropping this table (min 5 chars):');
    if (!reason || reason.length < 5) {
      alert('A reason of at least 5 characters is required.');
      return;
    }

    void api.schemaDropTable(table, reason).then((result) => {
      if (result.success) {
        window.location.href = '/admin/schema';
      } else {
        alert(result.message);
      }
    });
  });
}
