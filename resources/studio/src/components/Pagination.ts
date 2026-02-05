export interface PaginationOptions {
  total: number;
  limit: number;
  offset: number;
  baseUrl: string;
}

export function renderPagination(options: PaginationOptions): string {
  const totalPages = Math.ceil(options.total / options.limit);
  const currentPage = Math.floor(options.offset / options.limit) + 1;

  if (totalPages <= 1) return '';

  const pages: string[] = [];

  if (currentPage > 1) {
    const prevOffset = (currentPage - 2) * options.limit;
    pages.push(
      `<a href="${options.baseUrl}?offset=${String(prevOffset)}&limit=${String(options.limit)}" class="page-link">&laquo; Prev</a>`,
    );
  }

  pages.push(`<span class="page-info">Page ${String(currentPage)} of ${String(totalPages)}</span>`);

  if (currentPage < totalPages) {
    const nextOffset = currentPage * options.limit;
    pages.push(
      `<a href="${options.baseUrl}?offset=${String(nextOffset)}&limit=${String(options.limit)}" class="page-link">Next &raquo;</a>`,
    );
  }

  return `<div class="pagination">${pages.join('')}</div>`;
}
