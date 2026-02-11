/**
 * Global search component with debounced input.
 */
import { api } from '../api.js';

export interface GlobalSearchConfig {
  container: HTMLElement;
  onResultClick?: (resource: string, id: string) => void;
}

export function renderGlobalSearch(config: GlobalSearchConfig): void {
  const { container, onResultClick } = config;

  const wrapper = document.createElement('div');
  wrapper.className = 'admin-global-search';

  const input = document.createElement('input');
  input.type = 'search';
  input.className = 'admin-global-search__input';
  input.placeholder = 'Search all resources...';
  input.setAttribute('aria-label', 'Global search');
  wrapper.appendChild(input);

  const results = document.createElement('div');
  results.className = 'admin-global-search__results';
  results.style.display = 'none';
  wrapper.appendChild(results);

  let debounceTimer: ReturnType<typeof setTimeout>;

  input.addEventListener('input', () => {
    clearTimeout(debounceTimer);
    const query = input.value.trim();
    if (query.length < 2) {
      results.style.display = 'none';
      return;
    }
    debounceTimer = setTimeout(async () => {
      try {
        const data = await api.search(query);
        renderSearchResults(results, data.results, onResultClick);
        results.style.display = 'block';
      } catch {
        results.style.display = 'none';
      }
    }, 300);
  });

  document.addEventListener('click', (e) => {
    if (!wrapper.contains(e.target as Node)) {
      results.style.display = 'none';
    }
  });

  container.innerHTML = '';
  container.appendChild(wrapper);
}

function renderSearchResults(
  container: HTMLElement,
  results: Record<string, Array<Record<string, unknown>>>,
  onResultClick?: (resource: string, id: string) => void,
): void {
  container.innerHTML = '';

  const entries = Object.entries(results);
  if (entries.length === 0) {
    container.textContent = 'No results found';
    return;
  }

  for (const [resourceName, rows] of entries) {
    const section = document.createElement('div');
    section.className = 'admin-global-search__section';

    const heading = document.createElement('h4');
    heading.textContent = resourceName;
    section.appendChild(heading);

    const list = document.createElement('ul');
    for (const row of rows) {
      const li = document.createElement('li');
      const link = document.createElement('a');
      link.href = '#';
      const values = Object.values(row);
      link.textContent = values.slice(0, 3).join(' - ');
      link.addEventListener('click', (e) => {
        e.preventDefault();
        const id = String(row.id ?? values[0] ?? '');
        onResultClick?.(resourceName, id);
      });
      li.appendChild(link);
      list.appendChild(li);
    }
    section.appendChild(list);
    container.appendChild(section);
  }
}
