/**
 * Admin panel API client.
 *
 * Handles all HTTP communication with the admin backend,
 * including CSRF token management and error handling.
 */
import type {
  ActionHistoryEntry,
  ActionResult,
  DashboardData,
  ExportOptions,
  PaginatedResult,
  SavedView,
  SchemaActionResult,
  SchemaChangeLogEntry,
  SchemaColumnDef,
  SchemaIndexDef,
  SchemaPreviewResult,
  SearchResults,
  SortDirection,
  TableStructure,
} from './types.js';

let csrfToken: string | null = null;

function getCsrfToken(): string {
  if (csrfToken) {
    return csrfToken;
  }
  const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');
  return meta?.content ?? '';
}

function updateCsrfToken(response: Response): void {
  const newToken = response.headers.get('X-CSRF-Token');
  if (newToken) {
    csrfToken = newToken;
  }
}

async function request<T>(url: string, options: RequestInit = {}): Promise<T> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    ...(options.headers as Record<string, string>),
  };

  if (options.method && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(options.method)) {
    headers['X-CSRF-Token'] = getCsrfToken();
    headers['Content-Type'] = 'application/json';
  }

  const response = await fetch(url, { ...options, headers });

  updateCsrfToken(response);

  if (!response.ok) {
    const error = await response.json().catch(() => ({ error: 'Request failed' }));
    throw new Error((error as { error: string }).error || `HTTP ${response.status}`);
  }

  return response.json() as Promise<T>;
}

export const api = {
  dashboard(): Promise<DashboardData> {
    return request<DashboardData>('/admin');
  },

  resources(): Promise<{
    resources: Array<{ name: string; label: string; icon: string; operations: string[] }>;
  }> {
    return request('/admin/resources');
  },

  listRecords(
    resource: string,
    page = 1,
    perPage = 25,
    filters: Record<string, unknown> = {},
    sort: Record<string, SortDirection> = {},
  ): Promise<PaginatedResult<Record<string, unknown>>> {
    const params = new URLSearchParams({
      page: String(page),
      per_page: String(perPage),
    });

    if (Object.keys(filters).length > 0) {
      params.set('filters', JSON.stringify(filters));
    }

    const sortEntries = Object.entries(sort);
    if (sortEntries.length > 0) {
      const [sortField, sortDir] = sortEntries[0]!;
      params.set('sort_field', sortField);
      params.set('sort_dir', sortDir);
    }

    return request(`/admin/resources/${resource}?${params.toString()}`);
  },

  viewRecord(resource: string, id: string): Promise<{ data: Record<string, unknown> }> {
    return request(`/admin/resources/${resource}/${id}`);
  },

  createRecord(resource: string, data: Record<string, unknown>): Promise<ActionResult> {
    return request(`/admin/resources/${resource}`, {
      method: 'POST',
      body: JSON.stringify(data),
    });
  },

  updateRecord(resource: string, id: string, data: Record<string, unknown>): Promise<ActionResult> {
    return request(`/admin/resources/${resource}/${id}`, {
      method: 'PUT',
      body: JSON.stringify(data),
    });
  },

  deleteRecord(resource: string, id: string, reason?: string): Promise<ActionResult> {
    return request(`/admin/resources/${resource}/${id}`, {
      method: 'DELETE',
      body: JSON.stringify({ reason }),
    });
  },

  bulkAction(
    resource: string,
    action: string,
    ids: string[],
    parameters: Record<string, unknown> = {},
  ): Promise<ActionResult> {
    return request(`/admin/resources/${resource}/bulk`, {
      method: 'POST',
      body: JSON.stringify({ action, ids, parameters }),
    });
  },

  export(resource: string, options: ExportOptions): void {
    const params = new URLSearchParams({ format: options.format });
    if (options.filters && Object.keys(options.filters).length > 0) {
      params.set('filters', JSON.stringify(options.filters));
    }
    window.location.href = `/admin/resources/${resource}/export?${params.toString()}`;
  },

  search(query: string): Promise<SearchResults> {
    return request(`/admin/search?q=${encodeURIComponent(query)}`);
  },

  savedViews(resource: string): Promise<{ views: SavedView[] }> {
    return request(`/admin/resources/${resource}/views`);
  },

  saveSavedView(resource: string, view: Omit<SavedView, 'id'>): Promise<{ success: boolean }> {
    return request(`/admin/resources/${resource}/views`, {
      method: 'POST',
      body: JSON.stringify(view),
    });
  },

  deleteSavedView(resource: string, viewId: string): Promise<{ success: boolean }> {
    return request(`/admin/resources/${resource}/views/${viewId}`, {
      method: 'DELETE',
    });
  },

  actionHistory(limit = 50): Promise<{ entries: ActionHistoryEntry[] }> {
    return request(`/admin/history?limit=${limit}`);
  },

  resourceHistory(resource: string, limit = 50): Promise<{ entries: ActionHistoryEntry[] }> {
    return request(`/admin/history/${resource}?limit=${limit}`);
  },

  // Schema Builder API

  schemaPreviewCreate(def: TableStructure): Promise<SchemaPreviewResult> {
    return request('/admin/api/schema/preview/create', {
      method: 'POST',
      body: JSON.stringify(def),
    });
  },

  schemaPreviewAddColumn(table: string, column: SchemaColumnDef): Promise<SchemaPreviewResult> {
    return request(`/admin/api/schema/preview/${table}/add-column`, {
      method: 'POST',
      body: JSON.stringify(column),
    });
  },

  schemaPreviewDropColumn(table: string, column: string): Promise<SchemaPreviewResult> {
    return request(`/admin/api/schema/preview/${table}/drop-column`, {
      method: 'POST',
      body: JSON.stringify({ column }),
    });
  },

  schemaPreviewAddIndex(table: string, index: SchemaIndexDef): Promise<SchemaPreviewResult> {
    return request(`/admin/api/schema/preview/${table}/add-index`, {
      method: 'POST',
      body: JSON.stringify(index),
    });
  },

  schemaPreviewDropIndex(table: string, name: string): Promise<SchemaPreviewResult> {
    return request(`/admin/api/schema/preview/${table}/drop-index`, {
      method: 'POST',
      body: JSON.stringify({ name }),
    });
  },

  schemaPreviewDropTable(table: string): Promise<SchemaPreviewResult> {
    return request(`/admin/api/schema/preview/${table}/drop`, {
      method: 'POST',
    });
  },

  schemaPreviewRenameTable(table: string, newName: string): Promise<SchemaPreviewResult> {
    return request(`/admin/api/schema/preview/${table}/rename`, {
      method: 'POST',
      body: JSON.stringify({ new_name: newName }),
    });
  },

  schemaCreate(def: TableStructure, reason: string): Promise<SchemaActionResult> {
    return request('/admin/api/schema', {
      method: 'POST',
      body: JSON.stringify({ ...def, reason }),
    });
  },

  schemaDropTable(table: string, reason: string): Promise<SchemaActionResult> {
    return request(`/admin/api/schema/${table}`, {
      method: 'DELETE',
      body: JSON.stringify({ reason }),
    });
  },

  schemaRenameTable(table: string, newName: string, reason: string): Promise<SchemaActionResult> {
    return request(`/admin/api/schema/${table}/rename`, {
      method: 'POST',
      body: JSON.stringify({ new_name: newName, reason }),
    });
  },

  schemaAddColumn(
    table: string,
    column: SchemaColumnDef,
    reason: string,
  ): Promise<SchemaActionResult> {
    return request(`/admin/api/schema/${table}/columns`, {
      method: 'POST',
      body: JSON.stringify({ ...column, reason }),
    });
  },

  schemaDropColumn(table: string, column: string, reason: string): Promise<SchemaActionResult> {
    return request(`/admin/api/schema/${table}/columns/${column}`, {
      method: 'DELETE',
      body: JSON.stringify({ reason }),
    });
  },

  schemaAddIndex(
    table: string,
    index: SchemaIndexDef,
    reason: string,
  ): Promise<SchemaActionResult> {
    return request(`/admin/api/schema/${table}/indexes`, {
      method: 'POST',
      body: JSON.stringify({ ...index, reason }),
    });
  },

  schemaDropIndex(table: string, name: string, reason: string): Promise<SchemaActionResult> {
    return request(`/admin/api/schema/${table}/indexes/${name}`, {
      method: 'DELETE',
      body: JSON.stringify({ reason }),
    });
  },

  schemaChangelog(limit = 100): Promise<{ entries: SchemaChangeLogEntry[] }> {
    return request(`/admin/api/schema/changelog?limit=${limit}`);
  },

  schemaExportBundle(): void {
    window.location.href = '/admin/api/schema/changelog/export';
  },
};
