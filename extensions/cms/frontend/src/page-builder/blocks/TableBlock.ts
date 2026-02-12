/**
 * Page builder table block.
 *
 * `<cms-pb-table>` renders an editable HTML table with add/remove
 * rows and columns. Each cell is directly editable.
 */

export class CmsPbTable extends HTMLElement {
  private blockData: Record<string, unknown> = {};

  connectedCallback(): void {
    this.classList.add('pb-block-table');
    this.render();
  }

  setData(data: Record<string, unknown>): void {
    this.blockData = { ...data };
    this.render();
  }

  getData(): Record<string, unknown> {
    return { ...this.blockData };
  }

  private getHeaders(): string[] {
    const headers = this.blockData['headers'];
    if (Array.isArray(headers)) {
      return headers.map((h) => (typeof h === 'string' ? h : ''));
    }
    return ['Column 1', 'Column 2', 'Column 3'];
  }

  private getRows(): string[][] {
    const rows = this.blockData['rows'];
    if (Array.isArray(rows)) {
      return rows.map((row) => {
        if (Array.isArray(row)) {
          return row.map((cell) => (typeof cell === 'string' ? cell : ''));
        }
        return [];
      });
    }
    return [['', '', '']];
  }

  private render(): void {
    this.innerHTML = '';

    const headers = this.getHeaders();
    const rows = this.getRows();

    const wrapper = document.createElement('div');
    wrapper.className = 'pb-block-table__wrapper';

    // Table
    const tableWrapper = document.createElement('div');
    tableWrapper.className = 'pb-block-table__scroll';

    const table = document.createElement('table');
    table.className = 'pb-block-table__table';

    // Header row
    const thead = document.createElement('thead');
    const headerRow = document.createElement('tr');

    for (let col = 0; col < headers.length; col++) {
      const th = document.createElement('th');
      th.className = 'pb-block-table__header-cell';

      const input = document.createElement('input');
      input.type = 'text';
      input.className = 'pb-block-table__cell-input pb-block-table__cell-input--header';
      input.value = headers[col] ?? '';
      input.setAttribute('aria-label', `Header column ${col + 1}`);

      const colIdx = col;
      input.addEventListener('input', () => {
        const current = this.getHeaders();
        current[colIdx] = input.value;
        this.blockData['headers'] = current;
        this.emitUpdate();
      });
      th.appendChild(input);

      // Remove column button
      if (headers.length > 1) {
        const removeCol = document.createElement('button');
        removeCol.type = 'button';
        removeCol.className = 'pb-block-table__remove-col';
        removeCol.textContent = '\u{2715}';
        removeCol.title = `Remove column ${col + 1}`;
        removeCol.setAttribute('aria-label', `Remove column ${col + 1}`);
        removeCol.addEventListener('click', () => {
          this.removeColumn(colIdx);
        });
        th.appendChild(removeCol);
      }

      headerRow.appendChild(th);
    }

    thead.appendChild(headerRow);
    table.appendChild(thead);

    // Body rows
    const tbody = document.createElement('tbody');

    for (let row = 0; row < rows.length; row++) {
      const tr = document.createElement('tr');

      for (let col = 0; col < headers.length; col++) {
        const td = document.createElement('td');
        td.className = 'pb-block-table__cell';

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'pb-block-table__cell-input';
        input.value = rows[row]?.[col] ?? '';
        input.setAttribute('aria-label', `Row ${row + 1}, column ${col + 1}`);

        const rowIdx = row;
        const colIdx = col;
        input.addEventListener('input', () => {
          const currentRows = this.getRows();
          if (!currentRows[rowIdx]) {
            currentRows[rowIdx] = [];
          }
          currentRows[rowIdx][colIdx] = input.value;
          this.blockData['rows'] = currentRows;
          this.emitUpdate();
        });

        td.appendChild(input);
        tr.appendChild(td);
      }

      // Remove row button
      if (rows.length > 1) {
        const removeCell = document.createElement('td');
        removeCell.className = 'pb-block-table__remove-row-cell';
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'pb-block-table__remove-row';
        removeBtn.textContent = '\u{2715}';
        removeBtn.title = `Remove row ${row + 1}`;
        removeBtn.setAttribute('aria-label', `Remove row ${row + 1}`);
        const rowIdx = row;
        removeBtn.addEventListener('click', () => {
          this.removeRow(rowIdx);
        });
        removeCell.appendChild(removeBtn);
        tr.appendChild(removeCell);
      }

      tbody.appendChild(tr);
    }

    table.appendChild(tbody);
    tableWrapper.appendChild(table);
    wrapper.appendChild(tableWrapper);

    // Action buttons
    const actions = document.createElement('div');
    actions.className = 'pb-block-table__actions';

    const addRowBtn = document.createElement('button');
    addRowBtn.type = 'button';
    addRowBtn.className = 'cms-btn cms-btn--outline';
    addRowBtn.textContent = '+ Add Row';
    addRowBtn.addEventListener('click', () => {
      this.addRow();
    });
    actions.appendChild(addRowBtn);

    const addColBtn = document.createElement('button');
    addColBtn.type = 'button';
    addColBtn.className = 'cms-btn cms-btn--outline';
    addColBtn.textContent = '+ Add Column';
    addColBtn.addEventListener('click', () => {
      this.addColumn();
    });
    actions.appendChild(addColBtn);

    wrapper.appendChild(actions);
    this.appendChild(wrapper);
  }

  private addRow(): void {
    const headers = this.getHeaders();
    const rows = this.getRows();
    rows.push(new Array(headers.length).fill(''));
    this.blockData['rows'] = rows;
    this.render();
    this.emitUpdate();
  }

  private removeRow(index: number): void {
    const rows = this.getRows();
    if (rows.length <= 1) return;
    rows.splice(index, 1);
    this.blockData['rows'] = rows;
    this.render();
    this.emitUpdate();
  }

  private addColumn(): void {
    const headers = this.getHeaders();
    const rows = this.getRows();
    headers.push(`Column ${headers.length + 1}`);
    for (const row of rows) {
      row.push('');
    }
    this.blockData['headers'] = headers;
    this.blockData['rows'] = rows;
    this.render();
    this.emitUpdate();
  }

  private removeColumn(index: number): void {
    const headers = this.getHeaders();
    const rows = this.getRows();
    if (headers.length <= 1) return;
    headers.splice(index, 1);
    for (const row of rows) {
      row.splice(index, 1);
    }
    this.blockData['headers'] = headers;
    this.blockData['rows'] = rows;
    this.render();
    this.emitUpdate();
  }

  private emitUpdate(): void {
    this.dispatchEvent(
      new CustomEvent('block-update', {
        bubbles: true,
        detail: this.getData(),
      }),
    );
  }
}

customElements.define('cms-pb-table', CmsPbTable);
