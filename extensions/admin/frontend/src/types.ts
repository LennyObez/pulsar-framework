/**
 * Admin panel TypeScript type definitions.
 */

export interface FieldDefinition {
  name: string;
  type: string;
  label: string;
  sortable: boolean;
  filterable: boolean;
  searchable: boolean;
  redacted: boolean;
  exportable: boolean;
  editable: boolean;
  visibleOnList: boolean;
  visibleOnDetail: boolean;
  visibleOnForm: boolean;
  enumValues: string[];
  placeholder: string | null;
  helpText: string | null;
}

export interface ResourceDefinition {
  name: string;
  label: string;
  pluralLabel: string;
  icon: string;
  operations: string[];
  fields: FieldDefinition[];
}

export interface PaginatedResult<T> {
  data: T[];
  total: number;
  page: number;
  per_page: number;
  total_pages: number;
}

export interface ActionResult {
  success: boolean;
  message: string;
  data?: Record<string, unknown>;
}

export interface SavedView {
  id: string;
  label: string;
  is_default: boolean;
  filters: Record<string, unknown>;
  sort: Record<string, string>;
  per_page: number;
}

export interface ActionHistoryEntry {
  id: string;
  action: string;
  resource: string;
  record_id: string | null;
  actor: string;
  timestamp: number;
  success: boolean;
  detail: string;
}

export interface WidgetData {
  id: string;
  label: string;
  size: 'small' | 'medium' | 'large';
  data: Record<string, unknown>;
}

export interface DashboardData {
  widgets: WidgetData[];
  resources: Array<{ name: string; label: string; icon: string }>;
}

export interface SearchResults {
  results: Record<string, Array<Record<string, unknown>>>;
  total_matches: number;
}

export interface ExportOptions {
  format: 'csv' | 'json';
  filters?: Record<string, unknown>;
}

export type SortDirection = 'asc' | 'desc';

export interface FilterState {
  filters: Record<string, unknown>;
  sort: Record<string, SortDirection>;
  page: number;
  perPage: number;
}

// Schema Builder types

export interface SchemaColumnDef {
  name: string;
  type: string;
  nullable: boolean;
  primaryKey: boolean;
  autoIncrement: boolean;
  unsigned: boolean;
  unique: boolean;
  hasDefault: boolean;
  defaultValue: string | null;
  defaultExpression: string | null;
  length: number | null;
  precision: number | null;
  scale: number | null;
  enumValues: string[];
}

export interface SchemaIndexDef {
  name: string;
  columns: string[];
  unique: boolean;
}

export type SchemaReferentialAction = 'RESTRICT' | 'CASCADE' | 'SET NULL' | 'NO ACTION';

export interface SchemaForeignKeyDef {
  name: string;
  columns: string[];
  referencedTable: string;
  referencedColumns: string[];
  onDelete: SchemaReferentialAction;
  onUpdate: SchemaReferentialAction;
}

export interface TableStructure {
  name: string;
  columns: SchemaColumnDef[];
  indexes: SchemaIndexDef[];
  foreignKeys: SchemaForeignKeyDef[];
}

export interface SchemaCapabilities {
  supportsDropColumn: boolean;
  supportsAlterColumnType: boolean;
  supportsForeignKeys: boolean;
  supportsTransactionalDdl: boolean;
  supportsNativeEnum: boolean;
  supportsAddForeignKey: boolean;
  supportsDropForeignKey: boolean;
  supportsUnsigned: boolean;
}

export interface SchemaWarning {
  code: string;
  message: string;
}

export interface SchemaPreviewResult {
  statements: string[];
  warnings: SchemaWarning[];
}

export interface SchemaChangeLogEntry {
  id: string;
  operation: string;
  table: string;
  actor: string;
  reason: string;
  timestamp: number;
  evidence_hash: string;
  success: boolean;
  statements?: string[];
}

export interface SchemaActionResult {
  success: boolean;
  message: string;
  sql?: string[];
}
