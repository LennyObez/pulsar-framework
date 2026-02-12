import type { BreakdownItem } from '../types';

export function renderBreakdownTable(container: HTMLElement, items: BreakdownItem[]): void {
  if (items.length === 0) {
    container.innerHTML = '<p class="breakdown-empty">No data for this period</p>';
    return;
  }

  const maxVisitors = Math.max(...items.map((i) => i.visitors));

  container.innerHTML = `
    <table class="breakdown-table__inner">
      <thead>
        <tr>
          <th class="breakdown-table__name">Name</th>
          <th class="breakdown-table__visitors">Visitors</th>
        </tr>
      </thead>
      <tbody>
        ${items
          .map((item) => {
            const pct = maxVisitors > 0 ? (item.visitors / maxVisitors) * 100 : 0;
            return `
            <tr class="breakdown-row">
              <td class="breakdown-row__name">
                <div class="breakdown-bar" style="width:${pct.toFixed(1)}%"></div>
                <span class="breakdown-row__text">${escapeHtml(item.name)}</span>
              </td>
              <td class="breakdown-row__visitors">${item.visitors.toLocaleString()}</td>
            </tr>
          `;
          })
          .join('')}
      </tbody>
    </table>
  `;
}

function escapeHtml(str: string): string {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}
