/**
 * Comments component for rendering and interacting with content comments.
 *
 * Supports threaded and flat display modes, sorting, pagination, Gravatar
 * avatars, and inline reply forms via `<cms-comment-form>`.
 *
 * @example
 * ```html
 * <cms-comments
 *   data-content-id="abc-123"
 *   data-api-url="/api/cms/comments"
 *   data-mode="threaded"
 *   data-max-depth="3"
 *   data-sort="newest">
 * </cms-comments>
 * ```
 */

import { cmsApi } from '../utils/api';
import './CommentForm';

interface CommentData {
  readonly id: string;
  readonly content_id: string;
  readonly parent_id: string | null;
  readonly author_id: string | null;
  readonly guest_name: string | null;
  readonly guest_email: string | null;
  readonly body: string;
  readonly status: string;
  readonly created_at: string;
  readonly edited_at: string | null;
  readonly gravatar_hash: string | null;
}

interface CommentsApiResponse {
  readonly comments: readonly CommentData[];
  readonly pagination: {
    readonly total: number;
    readonly has_more: boolean;
    readonly current_page: number;
    readonly last_page: number;
    readonly per_page: number;
  };
}

type SortOrder = 'newest' | 'oldest' | 'most-voted';

/**
 * Compute an MD5 hash of a string for Gravatar URL construction.
 *
 * Uses the SubtleCrypto API (async) but falls back to returning an
 * empty string when unavailable, which triggers the Gravatar default.
 */
async function md5Hex(input: string): Promise<string> {
  if (typeof crypto === 'undefined' || !crypto.subtle) {
    return '';
  }

  const encoder = new TextEncoder();
  const data = encoder.encode(input.trim().toLowerCase());
  const hashBuffer = await crypto.subtle.digest('MD5', data).catch(() => null);

  if (hashBuffer === null) {
    return '';
  }

  const hashArray = Array.from(new Uint8Array(hashBuffer));

  return hashArray.map((b) => b.toString(16).padStart(2, '0')).join('');
}

/**
 * Synchronously produce a simple hash for Gravatar when SubtleCrypto
 * is unavailable. This uses a basic implementation of the DJB2 hash
 * converted to a hex string, which will serve as the Gravatar key
 * (triggering the default avatar, which is acceptable).
 */
function simpleHash(input: string): string {
  const str = input.trim().toLowerCase();
  let hash = 5381;

  for (let i = 0; i < str.length; i++) {
    hash = (hash * 33) ^ str.charCodeAt(i);
  }

  return (hash >>> 0).toString(16).padStart(8, '0');
}

function gravatarUrl(hash: string): string {
  if (hash === '') {
    return `https://www.gravatar.com/avatar/?d=mp&s=48`;
  }

  return `https://www.gravatar.com/avatar/${encodeURIComponent(hash)}?d=mp&s=48`;
}

function formatTimestamp(isoString: string): string {
  try {
    const date = new Date(isoString);

    return date.toLocaleDateString(undefined, {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  } catch {
    return isoString;
  }
}

export class CommentsComponent extends HTMLElement {
  private contentId = '';
  private apiUrl = '/api/cms/comments';
  private mode: 'threaded' | 'flat' = 'threaded';
  private maxDepth = 3;
  private sortOrder: SortOrder = 'newest';
  private currentPage = 1;
  private hasMore = false;
  private comments: CommentData[] = [];
  private container: HTMLElement | null = null;
  private sortBar: HTMLElement | null = null;
  private commentsList: HTMLElement | null = null;
  private loadMoreBtn: HTMLButtonElement | null = null;
  private loadingIndicator: HTMLElement | null = null;
  private isLoading = false;

  connectedCallback(): void {
    this.contentId = this.dataset.contentId ?? '';
    this.apiUrl = this.dataset.apiUrl ?? '/api/cms/comments';
    this.mode = this.dataset.mode === 'flat' ? 'flat' : 'threaded';
    this.maxDepth = parseInt(this.dataset.maxDepth ?? '3', 10) || 3;
    this.sortOrder = (this.dataset.sort as SortOrder) ?? 'newest';

    if (
      this.sortOrder !== 'newest' &&
      this.sortOrder !== 'oldest' &&
      this.sortOrder !== 'most-voted'
    ) {
      this.sortOrder = 'newest';
    }

    this.buildShell();
    this.addEventListener(
      'comment-submitted',
      this.handleCommentSubmitted.bind(this) as EventListener,
    );

    if (this.contentId !== '') {
      void this.fetchComments(true);
    }
  }

  disconnectedCallback(): void {
    this.removeEventListener(
      'comment-submitted',
      this.handleCommentSubmitted.bind(this) as EventListener,
    );
  }

  private buildShell(): void {
    this.classList.add('cms-comments');

    // Sort controls
    this.sortBar = document.createElement('div');
    this.sortBar.className = 'cms-comments__sort-bar';
    this.sortBar.setAttribute('role', 'toolbar');
    this.sortBar.setAttribute('aria-label', 'Comment sort order');

    const sortOptions: Array<{ value: SortOrder; label: string }> = [
      { value: 'newest', label: 'Newest' },
      { value: 'oldest', label: 'Oldest' },
      { value: 'most-voted', label: 'Most Voted' },
    ];

    for (const opt of sortOptions) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'cms-comments__sort-btn';

      if (opt.value === this.sortOrder) {
        btn.classList.add('cms-comments__sort-btn--active');
        btn.setAttribute('aria-pressed', 'true');
      } else {
        btn.setAttribute('aria-pressed', 'false');
      }

      btn.textContent = opt.label;
      btn.dataset.sort = opt.value;

      btn.addEventListener('click', () => {
        this.changeSort(opt.value);
      });

      this.sortBar.appendChild(btn);
    }

    this.appendChild(this.sortBar);

    // Top-level comment form
    const topForm = document.createElement('cms-comment-form');
    topForm.dataset.contentId = this.contentId;
    topForm.dataset.apiUrl = this.apiUrl;
    this.appendChild(topForm);

    // Container for the comments list
    this.container = document.createElement('div');
    this.container.className = 'cms-comments__container';

    this.commentsList = document.createElement('div');
    this.commentsList.className = 'cms-comments__list';
    this.commentsList.setAttribute('role', 'feed');
    this.commentsList.setAttribute('aria-label', 'Comments');
    this.container.appendChild(this.commentsList);

    // Loading indicator
    this.loadingIndicator = document.createElement('div');
    this.loadingIndicator.className = 'cms-comments__loading';
    this.loadingIndicator.textContent = 'Loading comments\u2026';
    this.loadingIndicator.hidden = true;
    this.container.appendChild(this.loadingIndicator);

    // Load more button
    this.loadMoreBtn = document.createElement('button');
    this.loadMoreBtn.type = 'button';
    this.loadMoreBtn.className = 'cms-comments__load-more';
    this.loadMoreBtn.textContent = 'Load more comments';
    this.loadMoreBtn.hidden = true;
    this.loadMoreBtn.addEventListener('click', () => {
      this.loadMore();
    });
    this.container.appendChild(this.loadMoreBtn);

    this.appendChild(this.container);
  }

  private changeSort(order: SortOrder): void {
    if (order === this.sortOrder) {
      return;
    }

    this.sortOrder = order;

    // Update button states
    const buttons = this.sortBar?.querySelectorAll<HTMLButtonElement>('.cms-comments__sort-btn');

    buttons?.forEach((btn) => {
      const isActive = btn.dataset.sort === order;
      btn.classList.toggle('cms-comments__sort-btn--active', isActive);
      btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });

    this.comments = [];
    this.currentPage = 1;
    void this.fetchComments(true);
  }

  private async fetchComments(reset: boolean): Promise<void> {
    if (this.isLoading) {
      return;
    }

    this.isLoading = true;

    if (this.loadingIndicator) {
      this.loadingIndicator.hidden = false;
    }

    if (this.loadMoreBtn) {
      this.loadMoreBtn.hidden = true;
    }

    const url = new URL(this.apiUrl, window.location.origin);
    url.searchParams.set('content_id', this.contentId);
    url.searchParams.set('page', String(this.currentPage));
    url.searchParams.set('sort', this.sortOrder);
    url.searchParams.set('status', 'approved');

    try {
      const response = await cmsApi(url.toString());

      if (!response.ok) {
        this.renderError();
        return;
      }

      const data: CommentsApiResponse = await response.json();

      if (reset) {
        this.comments = [...data.comments];
      } else {
        this.comments = [...this.comments, ...data.comments];
      }

      this.hasMore = data.pagination.has_more;
      this.renderComments();
    } catch {
      this.renderError();
    } finally {
      this.isLoading = false;

      if (this.loadingIndicator) {
        this.loadingIndicator.hidden = true;
      }

      if (this.loadMoreBtn) {
        this.loadMoreBtn.hidden = !this.hasMore;
      }
    }
  }

  private loadMore(): void {
    this.currentPage++;
    void this.fetchComments(false);
  }

  private renderComments(): void {
    if (!this.commentsList) {
      return;
    }

    this.commentsList.textContent = '';

    if (this.comments.length === 0) {
      const empty = document.createElement('p');
      empty.className = 'cms-comments__empty';
      empty.textContent = 'No comments yet. Be the first to comment!';
      this.commentsList.appendChild(empty);
      return;
    }

    if (this.mode === 'threaded') {
      this.renderThreaded();
    } else {
      this.renderFlat();
    }
  }

  private renderThreaded(): void {
    if (!this.commentsList) {
      return;
    }

    // Build a parent-children map
    const rootComments: CommentData[] = [];
    const childrenMap = new Map<string, CommentData[]>();

    for (const comment of this.comments) {
      if (comment.parent_id === null) {
        rootComments.push(comment);
      } else {
        const siblings = childrenMap.get(comment.parent_id) ?? [];
        siblings.push(comment);
        childrenMap.set(comment.parent_id, siblings);
      }
    }

    for (const root of rootComments) {
      this.renderThreadedComment(root, childrenMap, 0, this.commentsList);
    }
  }

  private renderThreadedComment(
    comment: CommentData,
    childrenMap: Map<string, CommentData[]>,
    depth: number,
    parent: HTMLElement,
  ): void {
    const el = this.buildCommentElement(comment, depth);
    parent.appendChild(el);

    const children = childrenMap.get(comment.id) ?? [];

    if (children.length > 0 && depth < this.maxDepth) {
      const threadContainer = document.createElement('div');
      threadContainer.className = 'cms-comments__thread';
      threadContainer.dataset.parentId = comment.id;

      // Collapse/expand toggle
      const toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'cms-comments__thread-toggle';
      toggle.setAttribute('aria-expanded', 'true');

      const replyCount = children.length;
      toggle.textContent = `${replyCount} ${replyCount === 1 ? 'reply' : 'replies'}`;

      toggle.addEventListener('click', () => {
        const isExpanded = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
        threadContainer.hidden = isExpanded;
        toggle.textContent = isExpanded
          ? `Show ${replyCount} ${replyCount === 1 ? 'reply' : 'replies'}`
          : `${replyCount} ${replyCount === 1 ? 'reply' : 'replies'}`;
      });

      el.appendChild(toggle);

      for (const child of children) {
        this.renderThreadedComment(child, childrenMap, depth + 1, threadContainer);
      }

      el.appendChild(threadContainer);
    } else if (children.length > 0) {
      // At max depth, render children flat
      for (const child of children) {
        const childEl = this.buildCommentElement(child, depth + 1);
        parent.appendChild(childEl);
      }
    }
  }

  private renderFlat(): void {
    if (!this.commentsList) {
      return;
    }

    for (const comment of this.comments) {
      const el = this.buildCommentElement(comment, 0);

      // Add "in reply to" link for flat mode
      if (comment.parent_id !== null) {
        const replyRef = document.createElement('span');
        replyRef.className = 'cms-comment__reply-ref';

        const link = document.createElement('a');
        link.href = `#comment-${encodeURIComponent(comment.parent_id)}`;
        link.className = 'cms-comment__reply-ref-link';
        link.textContent = 'in reply to a comment';
        link.addEventListener('click', (e) => {
          e.preventDefault();
          const target = this.querySelector(`#comment-${CSS.escape(comment.parent_id ?? '')}`);
          target?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });

        replyRef.appendChild(link);

        const meta = el.querySelector('.cms-comment__meta');
        meta?.appendChild(replyRef);
      }

      this.commentsList.appendChild(el);
    }
  }

  private buildCommentElement(comment: CommentData, depth: number): HTMLElement {
    const article = document.createElement('article');
    article.className = 'cms-comment';
    article.id = `comment-${comment.id}`;
    article.setAttribute('role', 'article');

    if (depth > 0) {
      article.classList.add('cms-comment--reply');
      article.style.marginLeft = `${Math.min(depth, this.maxDepth) * 2}rem`;
    }

    // Avatar
    const avatar = document.createElement('img');
    avatar.className = 'cms-comment__avatar';
    avatar.width = 48;
    avatar.height = 48;
    avatar.loading = 'lazy';
    avatar.alt = '';

    const emailForHash = comment.guest_email ?? '';

    if (emailForHash !== '') {
      // Set a synchronous fallback first, then attempt async MD5
      avatar.src = gravatarUrl(simpleHash(emailForHash));
      md5Hex(emailForHash).then((hash) => {
        if (hash !== '') {
          avatar.src = gravatarUrl(hash);
        }
      });
    } else {
      avatar.src = gravatarUrl('');
    }

    article.appendChild(avatar);

    // Content wrapper
    const content = document.createElement('div');
    content.className = 'cms-comment__content';

    // Meta
    const meta = document.createElement('div');
    meta.className = 'cms-comment__meta';

    const authorName = document.createElement('span');
    authorName.className = 'cms-comment__author';
    authorName.textContent = comment.guest_name ?? 'Anonymous';
    meta.appendChild(authorName);

    const timestamp = document.createElement('time');
    timestamp.className = 'cms-comment__time';
    timestamp.dateTime = comment.created_at;
    timestamp.textContent = formatTimestamp(comment.created_at);
    meta.appendChild(timestamp);

    if (comment.edited_at !== null) {
      const edited = document.createElement('span');
      edited.className = 'cms-comment__edited';
      edited.textContent = '(edited)';
      meta.appendChild(edited);
    }

    content.appendChild(meta);

    // Body
    const body = document.createElement('div');
    body.className = 'cms-comment__body';
    body.textContent = comment.body;
    content.appendChild(body);

    // Actions
    const actions = document.createElement('div');
    actions.className = 'cms-comment__actions';

    const replyBtn = document.createElement('button');
    replyBtn.type = 'button';
    replyBtn.className = 'cms-comment__action-btn';
    replyBtn.textContent = 'Reply';

    replyBtn.addEventListener('click', () => {
      this.toggleReplyForm(comment.id, article);
    });

    actions.appendChild(replyBtn);
    content.appendChild(actions);

    article.appendChild(content);

    return article;
  }

  private toggleReplyForm(parentId: string, parentElement: HTMLElement): void {
    const existingForm = parentElement.querySelector<HTMLElement>(
      `:scope > cms-comment-form[data-parent-id="${CSS.escape(parentId)}"]`,
    );

    if (existingForm) {
      existingForm.remove();
      return;
    }

    // Remove any other open reply forms in this component
    this.querySelectorAll<HTMLElement>('cms-comment-form[data-parent-id]').forEach((form) => {
      form.remove();
    });

    const form = document.createElement('cms-comment-form');
    form.dataset.contentId = this.contentId;
    form.dataset.parentId = parentId;
    form.dataset.apiUrl = this.apiUrl;
    form.classList.add('cms-comment-form--reply');
    parentElement.appendChild(form);
  }

  private handleCommentSubmitted(event: Event): void {
    const customEvent = event as CustomEvent;
    const detail = customEvent.detail as
      { comment?: CommentData; parent_id?: string | null } | undefined;

    if (detail?.parent_id) {
      // Remove the inline reply form
      const form = this.querySelector<HTMLElement>(
        `cms-comment-form[data-parent-id="${CSS.escape(detail.parent_id)}"]`,
      );
      form?.remove();
    }

    // Refresh comments to show the new one (if auto-approved)
    this.comments = [];
    this.currentPage = 1;
    void this.fetchComments(true);
  }

  private renderError(): void {
    if (!this.commentsList) {
      return;
    }

    this.commentsList.textContent = '';

    const errorEl = document.createElement('p');
    errorEl.className = 'cms-comments__error';
    errorEl.textContent = 'Failed to load comments. Please try again later.';
    this.commentsList.appendChild(errorEl);
  }
}

customElements.define('cms-comments', CommentsComponent);
