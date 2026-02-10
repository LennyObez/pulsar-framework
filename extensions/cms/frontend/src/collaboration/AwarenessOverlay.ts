/**
 * Awareness overlay for collaborative editing.
 *
 * Renders other users' cursors and selections in the editor, with unique
 * colors and username tooltips. Updates via the collaboration API's
 * awareness endpoint.
 */

/** Session info from the collaboration API. */
interface SessionInfo {
  id: string;
  user_id: string;
  user_name: string;
  cursor_position: string | null;
  selection_range: string | null;
}

/** Parsed cursor position. */
interface CursorPosition {
  line: number;
  column: number;
}

/** Parsed selection range. */
interface SelectionRange {
  startLine: number;
  startColumn: number;
  endLine: number;
  endColumn: number;
}

/** Color palette for collaborator cursors. */
const COLLABORATOR_COLORS: readonly string[] = [
  '#e06c75',
  '#61afef',
  '#98c379',
  '#d19a66',
  '#c678dd',
  '#56b6c2',
  '#be5046',
  '#e5c07b',
] as const;

const AWARENESS_POLL_INTERVAL_MS = 2000;

export class AwarenessOverlay {
  private readonly container: HTMLElement;
  private readonly baseUrl: string;
  private contentId: string | null = null;
  private sessionId: string | null = null;
  private localUserId: string | null = null;
  private pollTimer: ReturnType<typeof setInterval> | null = null;
  private cursorElements: Map<string, HTMLElement> = new Map();
  private userColorMap: Map<string, string> = new Map();
  private nextColorIndex = 0;

  constructor(container: HTMLElement, baseUrl = '/api/v1/collaboration') {
    this.container = container;
    this.baseUrl = baseUrl;
  }

  /** Start tracking awareness for a content item. */
  start(contentId: string, sessionId: string, localUserId: string): void {
    this.contentId = contentId;
    this.sessionId = sessionId;
    this.localUserId = localUserId;

    this.pollTimer = setInterval(() => void this.fetchAndRender(), AWARENESS_POLL_INTERVAL_MS);
  }

  /** Stop tracking awareness and remove all overlays. */
  stop(): void {
    if (this.pollTimer !== null) {
      clearInterval(this.pollTimer);
      this.pollTimer = null;
    }

    for (const el of this.cursorElements.values()) {
      el.remove();
    }

    this.cursorElements.clear();
    this.contentId = null;
    this.sessionId = null;
  }

  /**
   * Publish the local user's cursor position and selection to the server.
   */
  async updateLocalAwareness(
    cursorPosition: CursorPosition | null,
    selectionRange: SelectionRange | null,
  ): Promise<void> {
    if (this.contentId === null || this.sessionId === null) {
      return;
    }

    await fetch(`${this.baseUrl}/${this.contentId}/awareness`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        session_id: this.sessionId,
        cursor_position:
          cursorPosition !== null ? `${cursorPosition.line}:${cursorPosition.column}` : null,
        selection_range:
          selectionRange !== null
            ? `${selectionRange.startLine}:${selectionRange.startColumn}-${selectionRange.endLine}:${selectionRange.endColumn}`
            : null,
      }),
    });
  }

  /** Fetch sessions and render remote cursors. */
  private async fetchAndRender(): Promise<void> {
    if (this.contentId === null) {
      return;
    }

    try {
      const response = await fetch(`${this.baseUrl}/${this.contentId}/state`);
      const data = (await response.json()) as {
        sessions: SessionInfo[];
      };

      this.renderCursors(data.sessions);
    } catch {
      // Silently ignore fetch errors — next poll will retry
    }
  }

  /** Render cursor overlays for all remote collaborators. */
  private renderCursors(sessions: SessionInfo[]): void {
    const activeSessionIds = new Set<string>();

    for (const session of sessions) {
      // Skip the local user
      if (session.user_id === this.localUserId) {
        continue;
      }

      activeSessionIds.add(session.id);

      const color = this.getColorForUser(session.user_id);
      const cursorPos = this.parseCursorPosition(session.cursor_position);

      let el = this.cursorElements.get(session.id);

      if (el === undefined) {
        el = document.createElement('div');
        el.className = 'collab-cursor-overlay';
        el.style.position = 'absolute';
        el.style.pointerEvents = 'none';
        el.style.zIndex = '1000';
        this.container.appendChild(el);
        this.cursorElements.set(session.id, el);
      }

      if (cursorPos !== null) {
        el.style.display = 'block';
        el.innerHTML = this.renderCursorHtml(session.user_name, color, cursorPos);
      } else {
        el.style.display = 'none';
      }
    }

    // Remove cursors for sessions that are no longer active
    for (const [sessionId, el] of this.cursorElements) {
      if (!activeSessionIds.has(sessionId)) {
        el.remove();
        this.cursorElements.delete(sessionId);
      }
    }
  }

  /** Get a stable color for a user ID. */
  private getColorForUser(userId: string): string {
    const existing = this.userColorMap.get(userId);

    if (existing !== undefined) {
      return existing;
    }

    const color =
      COLLABORATOR_COLORS[this.nextColorIndex % COLLABORATOR_COLORS.length] ??
      COLLABORATOR_COLORS[0]!;
    this.nextColorIndex++;
    this.userColorMap.set(userId, color);

    return color;
  }

  /** Parse a cursor position string like "line:column". */
  private parseCursorPosition(value: string | null): CursorPosition | null {
    if (value === null) {
      return null;
    }

    const parts = value.split(':');
    const rawLine = parts[0];
    const rawColumn = parts[1];

    if (parts.length !== 2 || rawLine === undefined || rawColumn === undefined) {
      return null;
    }

    const line = parseInt(rawLine, 10);
    const column = parseInt(rawColumn, 10);

    if (isNaN(line) || isNaN(column)) {
      return null;
    }

    return { line, column };
  }

  /** Render HTML for a single collaborator's cursor. */
  private renderCursorHtml(userName: string, color: string, position: CursorPosition): string {
    const lineHeight = 20;
    const charWidth = 8;
    const top = (position.line - 1) * lineHeight;
    const left = position.column * charWidth;

    return `
      <div style="position: absolute; top: ${top}px; left: ${left}px;">
        <div style="width: 2px; height: ${lineHeight}px; background: ${color};"></div>
        <div style="
          position: absolute;
          top: -18px;
          left: 0;
          background: ${color};
          color: #fff;
          font-size: 11px;
          padding: 1px 4px;
          border-radius: 2px;
          white-space: nowrap;
          line-height: 14px;
        ">${this.escapeHtml(userName)}</div>
      </div>
    `;
  }

  /** Escape HTML special characters. */
  private escapeHtml(text: string): string {
    const div = document.createElement('div');
    div.textContent = text;

    return div.innerHTML;
  }
}
