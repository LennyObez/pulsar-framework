/**
 * Pagination component.
 */
export interface PaginationConfig {
  container: HTMLElement;
  page: number;
  totalPages: number;
  onChange: (page: number) => void;
}

export function renderPagination(config: PaginationConfig): void {
  const { container, page, totalPages, onChange } = config;

  if (totalPages <= 1) {
    container.innerHTML = '';
    return;
  }

  const nav = document.createElement('nav');
  nav.className = 'admin-pagination';
  nav.setAttribute('aria-label', 'Pagination');

  const ul = document.createElement('ul');

  if (page > 1) {
    const prevLi = document.createElement('li');
    const prevLink = document.createElement('a');
    prevLink.href = '#';
    prevLink.textContent = '\u00AB Prev';
    prevLink.addEventListener('click', (e) => {
      e.preventDefault();
      onChange(page - 1);
    });
    prevLi.appendChild(prevLink);
    ul.appendChild(prevLi);
  }

  const startPage = Math.max(1, page - 2);
  const endPage = Math.min(totalPages, page + 2);

  for (let i = startPage; i <= endPage; i++) {
    const li = document.createElement('li');
    li.className = i === page ? 'active' : '';
    if (i === page) {
      const span = document.createElement('span');
      span.textContent = String(i);
      span.setAttribute('aria-current', 'page');
      li.appendChild(span);
    } else {
      const link = document.createElement('a');
      link.href = '#';
      link.textContent = String(i);
      link.addEventListener('click', (e) => {
        e.preventDefault();
        onChange(i);
      });
      li.appendChild(link);
    }
    ul.appendChild(li);
  }

  if (page < totalPages) {
    const nextLi = document.createElement('li');
    const nextLink = document.createElement('a');
    nextLink.href = '#';
    nextLink.textContent = 'Next \u00BB';
    nextLink.addEventListener('click', (e) => {
      e.preventDefault();
      onChange(page + 1);
    });
    nextLi.appendChild(nextLink);
    ul.appendChild(nextLi);
  }

  nav.appendChild(ul);
  container.innerHTML = '';
  container.appendChild(nav);
}
