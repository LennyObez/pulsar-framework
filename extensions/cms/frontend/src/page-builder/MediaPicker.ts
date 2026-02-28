/**
 * Media picker dialog for the page builder.
 *
 * `<cms-media-picker>` is a native `<dialog>`-based modal for selecting
 * media assets from the CMS media library. Supports grid view with
 * thumbnails, drag-and-drop upload, debounced search, single/multi-select,
 * skeleton loading states, and full ARIA support.
 */

import { cmsApi } from '../utils/api.js';
import { escapeHtml } from '../utils/escapeHtml.js';

export interface MediaAsset {
  id: string;
  filename: string;
  mime_type: string;
  file_size: number;
  url: string;
  thumbnail_url?: string;
  alt?: string;
  width?: number;
  height?: number;
}

const SKELETON_COUNT = 12;

export class CmsMediaPicker extends HTMLElement {
  private dialog: HTMLDialogElement | null = null;
  private assets: MediaAsset[] = [];
  private filteredAssets: MediaAsset[] = [];
  private selectedIds: Set<string> = new Set();
  private searchQuery = '';
  private searchTimeout: ReturnType<typeof setTimeout> | null = null;
  private multiSelect = false;
  private apiBase = '/admin/api/media';
  private loading = false;

  connectedCallback(): void {
    const customApi = this.getAttribute('api-base');
    if (customApi) {
      this.apiBase = customApi;
    }
    this.buildDialog();
  }

  disconnectedCallback(): void {
    if (this.searchTimeout !== null) {
      clearTimeout(this.searchTimeout);
    }
  }

  /**
   * Open the media picker dialog.
   * @param multi Allow selecting multiple assets.
   */
  open(multi = false): void {
    this.multiSelect = multi;
    this.selectedIds.clear();
    this.searchQuery = '';
    void this.loadAssets();
    this.dialog?.showModal();
  }

  close(): void {
    this.dialog?.close();
  }

  private buildDialog(): void {
    this.dialog = document.createElement('dialog');
    this.dialog.className = 'mp-dialog';
    this.dialog.setAttribute('aria-label', 'Media library');

    this.dialog.addEventListener('close', () => {
      this.selectedIds.clear();
    });

    this.dialog.addEventListener('click', (e) => {
      if (e.target === this.dialog) {
        this.close();
      }
    });

    this.appendChild(this.dialog);
  }

  private async loadAssets(): Promise<void> {
    if (!this.dialog) return;

    this.loading = true;
    this.renderDialog();

    try {
      const response = await cmsApi(this.apiBase);
      if (!response.ok) {
        this.assets = [];
      } else {
        const data: unknown = await response.json();
        this.assets = this.parseAssets(data);
      }
    } catch {
      this.assets = [];
    }

    this.loading = false;
    this.filteredAssets = [...this.assets];
    this.renderDialog();
  }

  private parseAssets(data: unknown): MediaAsset[] {
    const raw = Array.isArray(data)
      ? data
      : typeof data === 'object' &&
          data !== null &&
          'items' in data &&
          Array.isArray((data as Record<string, unknown>).items)
        ? ((data as Record<string, unknown>).items as unknown[])
        : [];

    return raw.filter(
      (item): item is MediaAsset =>
        typeof item === 'object' &&
        item !== null &&
        typeof (item as Record<string, unknown>).id === 'string' &&
        typeof (item as Record<string, unknown>).filename === 'string' &&
        typeof (item as Record<string, unknown>).url === 'string',
    );
  }

  private renderDialog(): void {
    if (!this.dialog) return;
    this.dialog.innerHTML = '';

    // Header
    const header = document.createElement('div');
    header.className = 'mp-header';

    const title = document.createElement('h2');
    title.className = 'mp-title';
    title.textContent = this.multiSelect ? 'Select Media Files' : 'Select Media';
    header.appendChild(title);

    const searchInput = document.createElement('input');
    searchInput.type = 'search';
    searchInput.className = 'mp-search';
    searchInput.placeholder = 'Search media...';
    searchInput.value = this.searchQuery;
    searchInput.setAttribute('aria-label', 'Search media library');
    searchInput.addEventListener('input', () => {
      this.debounceSearch(searchInput.value);
    });
    header.appendChild(searchInput);

    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'mp-close';
    closeBtn.textContent = 'X';
    closeBtn.title = 'Close';
    closeBtn.setAttribute('aria-label', 'Close dialog');
    closeBtn.addEventListener('click', () => this.close());
    header.appendChild(closeBtn);

    this.dialog.appendChild(header);

    // Dropzone
    const dropzone = document.createElement('div');
    dropzone.className = 'mp-dropzone';
    dropzone.setAttribute('role', 'region');
    dropzone.setAttribute('aria-label', 'Upload dropzone');

    const dropText = document.createElement('div');
    dropText.className = 'mp-dropzone__text';
    dropText.textContent = 'Drag files here or click to upload';
    dropzone.appendChild(dropText);

    const fileInput = document.createElement('input');
    fileInput.type = 'file';
    fileInput.className = 'mp-dropzone__input';
    fileInput.multiple = true;
    fileInput.setAttribute('aria-label', 'Upload files');
    fileInput.addEventListener('change', () => {
      if (fileInput.files && fileInput.files.length > 0) {
        void this.uploadFiles(fileInput.files);
      }
    });
    dropzone.appendChild(fileInput);

    const statusEl = document.createElement('div');
    statusEl.className = 'mp-dropzone__status';
    dropzone.appendChild(statusEl);

    dropzone.addEventListener('click', (e) => {
      if (e.target !== fileInput) {
        fileInput.click();
      }
    });

    dropzone.addEventListener('dragover', (e) => {
      e.preventDefault();
      dropzone.classList.add('mp-dropzone--active');
    });

    dropzone.addEventListener('dragleave', () => {
      dropzone.classList.remove('mp-dropzone--active');
    });

    dropzone.addEventListener('drop', (e) => {
      e.preventDefault();
      dropzone.classList.remove('mp-dropzone--active');
      const files = e.dataTransfer?.files;
      if (files && files.length > 0) {
        void this.uploadFiles(files);
      }
    });

    this.dialog.appendChild(dropzone);

    // Grid
    const grid = document.createElement('div');
    grid.className = 'mp-grid';
    grid.setAttribute('role', 'listbox');
    grid.setAttribute(
      'aria-label',
      this.multiSelect ? 'Media assets (multi-select)' : 'Media assets',
    );
    if (this.multiSelect) {
      grid.setAttribute('aria-multiselectable', 'true');
    }

    if (this.loading) {
      // Skeleton loading states
      for (let i = 0; i < SKELETON_COUNT; i++) {
        const skeleton = document.createElement('div');
        skeleton.className = 'mp-item cms-skeleton';
        skeleton.setAttribute('aria-hidden', 'true');

        const thumbSkeleton = document.createElement('div');
        thumbSkeleton.className = 'mp-item__thumb cms-skeleton';
        skeleton.appendChild(thumbSkeleton);

        const nameSkeleton = document.createElement('div');
        nameSkeleton.className = 'mp-item__name cms-skeleton';
        nameSkeleton.innerHTML = '&nbsp;';
        skeleton.appendChild(nameSkeleton);

        grid.appendChild(skeleton);
      }
    } else if (this.filteredAssets.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'mp-grid__empty';
      empty.textContent =
        this.searchQuery !== ''
          ? 'No media matches your search.'
          : 'No media found. Upload files to get started.';
      grid.appendChild(empty);
    } else {
      for (const asset of this.filteredAssets) {
        const item = this.createAssetItem(asset);
        grid.appendChild(item);
      }
    }

    this.dialog.appendChild(grid);

    // Footer
    const footer = document.createElement('div');
    footer.className = 'mp-footer';

    const selectedCount = document.createElement('span');
    selectedCount.textContent = `${this.selectedIds.size} selected`;
    footer.appendChild(selectedCount);

    const confirmBtn = document.createElement('button');
    confirmBtn.type = 'button';
    confirmBtn.className = 'cms-btn cms-btn--primary';
    confirmBtn.textContent = 'Confirm';
    confirmBtn.disabled = this.selectedIds.size === 0;
    confirmBtn.addEventListener('click', () => {
      this.confirmSelection();
    });
    footer.appendChild(confirmBtn);

    this.dialog.appendChild(footer);

    if (!this.loading) {
      searchInput.focus();
    }
  }

  private createAssetItem(asset: MediaAsset): HTMLElement {
    const isSelected = this.selectedIds.has(asset.id);

    const item = document.createElement('div');
    item.className = isSelected ? 'mp-item mp-item--selected' : 'mp-item';
    item.tabIndex = 0;
    item.setAttribute('role', 'option');
    item.setAttribute('aria-selected', String(isSelected));
    item.setAttribute('aria-label', escapeHtml(asset.filename));
    item.dataset['assetId'] = asset.id;

    // Thumbnail area
    const thumb = document.createElement('div');
    thumb.className = 'mp-item__thumb';

    if (asset.mime_type.startsWith('image/')) {
      const img = document.createElement('img');
      img.src = asset.thumbnail_url ?? asset.url;
      img.alt = asset.alt ?? asset.filename;
      img.loading = 'lazy';
      thumb.appendChild(img);
    } else {
      const icon = document.createElement('span');
      icon.className = 'mp-item__icon';
      icon.textContent = this.getMimeIcon(asset.mime_type);
      thumb.appendChild(icon);
    }

    item.appendChild(thumb);

    // Filename
    const name = document.createElement('div');
    name.className = 'mp-item__name';
    name.textContent = asset.filename;
    item.appendChild(name);

    // Click handler
    item.addEventListener('click', (e) => {
      this.handleItemClick(asset, e);
    });

    item.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        this.handleItemClick(asset, e);
      }
    });

    return item;
  }

  private getMimeIcon(mimeType: string): string {
    if (mimeType.startsWith('video/')) return 'V';
    if (mimeType.startsWith('audio/')) return 'A';
    if (mimeType === 'application/pdf') return 'P';
    return 'F';
  }

  private handleItemClick(asset: MediaAsset, e: Event): void {
    const mouseEvent = e as MouseEvent | KeyboardEvent;

    if (this.multiSelect) {
      if (mouseEvent.ctrlKey || mouseEvent.metaKey) {
        if (this.selectedIds.has(asset.id)) {
          this.selectedIds.delete(asset.id);
        } else {
          this.selectedIds.add(asset.id);
        }
      } else {
        this.selectedIds.clear();
        this.selectedIds.add(asset.id);
      }
    } else {
      this.selectedIds.clear();
      this.selectedIds.add(asset.id);
    }

    this.renderDialog();
  }

  private debounceSearch(query: string): void {
    if (this.searchTimeout !== null) {
      clearTimeout(this.searchTimeout);
    }

    this.searchTimeout = setTimeout(() => {
      this.searchQuery = query.toLowerCase().trim();
      if (this.searchQuery === '') {
        this.filteredAssets = [...this.assets];
      } else {
        this.filteredAssets = this.assets.filter(
          (a) =>
            a.filename.toLowerCase().includes(this.searchQuery) ||
            (a.alt?.toLowerCase().includes(this.searchQuery) ?? false),
        );
      }
      this.renderDialog();
    }, 300);
  }

  private async uploadFiles(files: FileList): Promise<void> {
    const statusEl = this.dialog?.querySelector('.mp-dropzone__status');

    for (const file of files) {
      if (statusEl) {
        statusEl.textContent = `Uploading ${file.name}...`;
      }

      const formData = new FormData();
      formData.append('file', file);

      try {
        const response = await cmsApi(this.apiBase, {
          method: 'POST',
          body: formData,
        });

        if (response.ok) {
          const asset: unknown = await response.json();
          if (
            typeof asset === 'object' &&
            asset !== null &&
            typeof (asset as Record<string, unknown>).id === 'string'
          ) {
            this.assets.unshift(asset as MediaAsset);
          }
        }
      } catch {
        // Upload failed — user sees the asset was not added
      }
    }

    if (statusEl) {
      statusEl.textContent = '';
    }

    this.filteredAssets = [...this.assets];
    this.renderDialog();
  }

  private confirmSelection(): void {
    const selected = this.assets.filter((a) => this.selectedIds.has(a.id));

    this.dispatchEvent(
      new CustomEvent('media-selected', {
        bubbles: true,
        detail: { assets: selected },
      }),
    );

    this.close();
  }
}

customElements.define('cms-media-picker', CmsMediaPicker);
