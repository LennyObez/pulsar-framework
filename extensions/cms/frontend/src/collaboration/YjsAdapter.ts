/**
 * Yjs adapter for CRDT-based collaborative editing.
 *
 * Manages a Yjs document instance and synchronizes with the Pulsar CMS
 * collaboration API via REST polling. The server stores the latest CRDT
 * state snapshot; actual merging happens client-side in Yjs.
 */

import { cmsApi } from '../utils/api.js';

/** CRDT document state returned from the server. */
interface CrdtDocumentState {
  content_id: string;
  state_vector: string;
  version: number;
  updated_at: string;
}

/** Active collaboration session as reported by the server. */
interface CollaborationSessionInfo {
  id: string;
  user_id: string;
  user_name: string;
  cursor_position: string | null;
  selection_range: string | null;
  connected_at: string;
  last_seen_at: string;
}

/** Full state response from the server. */
interface CollaborationState {
  document: CrdtDocumentState | null;
  sessions: CollaborationSessionInfo[];
}

/** Join response from the server. */
interface JoinResponse {
  session_id: string;
  content_id: string;
  user_id: string;
  user_name: string;
  connected_at: string;
}

/** Callback for when a remote update is received. */
type UpdateCallback = (state: string, version: number) => void;

/** Callback for when sessions change. */
type SessionsCallback = (sessions: CollaborationSessionInfo[]) => void;

const DEFAULT_POLL_INTERVAL_MS = 2000;

export class YjsAdapter {
  private contentId: string | null = null;
  private sessionId: string | null = null;
  private pollTimer: ReturnType<typeof setInterval> | null = null;
  private lastVersion = 0;
  private onUpdateCallbacks: UpdateCallback[] = [];
  private onSessionsCallbacks: SessionsCallback[] = [];
  private readonly baseUrl: string;

  constructor(baseUrl = '/api/v1/collaboration') {
    this.baseUrl = baseUrl;
  }

  /**
   * Connect to a content item's collaboration session.
   * Starts polling for remote updates.
   */
  async connect(contentId: string, userId: string, userName: string): Promise<string> {
    this.contentId = contentId;

    const response = await cmsApi(`${this.baseUrl}/${contentId}/join`, {
      method: 'POST',
      body: JSON.stringify({ user_id: userId, user_name: userName }),
    });

    const data = (await response.json()) as JoinResponse;
    this.sessionId = data.session_id;

    // Fetch initial state
    await this.poll();

    // Start polling
    this.pollTimer = setInterval(() => void this.poll(), DEFAULT_POLL_INTERVAL_MS);

    return this.sessionId;
  }

  /** Disconnect from the collaboration session and stop polling. */
  async disconnect(): Promise<void> {
    if (this.pollTimer !== null) {
      clearInterval(this.pollTimer);
      this.pollTimer = null;
    }

    if (this.contentId !== null && this.sessionId !== null) {
      await cmsApi(`${this.baseUrl}/${this.contentId}/leave`, {
        method: 'POST',
        body: JSON.stringify({ session_id: this.sessionId }),
      });
    }

    this.contentId = null;
    this.sessionId = null;
    this.lastVersion = 0;
  }

  /**
   * Send a local CRDT state update to the server.
   * The update is a base64-encoded Yjs binary update.
   */
  async applyLocalUpdate(update: string, userId: string): Promise<number> {
    if (this.contentId === null) {
      throw new Error('Not connected to a collaboration session');
    }

    const response = await cmsApi(`${this.baseUrl}/${this.contentId}/update`, {
      method: 'POST',
      body: JSON.stringify({ update, user_id: userId }),
    });

    const data = (await response.json()) as { version: number };
    this.lastVersion = data.version;

    return data.version;
  }

  /** Get the current full state from the server. */
  async getRemoteState(): Promise<CollaborationState | null> {
    if (this.contentId === null) {
      return null;
    }

    const response = await fetch(`${this.baseUrl}/${this.contentId}/state`);

    return (await response.json()) as CollaborationState;
  }

  /** Register a callback for when remote updates are received. */
  onUpdate(callback: UpdateCallback): void {
    this.onUpdateCallbacks.push(callback);
  }

  /** Register a callback for when sessions change. */
  onSessionsChange(callback: SessionsCallback): void {
    this.onSessionsCallbacks.push(callback);
  }

  /** Poll the server for state updates. */
  private async poll(): Promise<void> {
    if (this.contentId === null) {
      return;
    }

    try {
      const state = await this.getRemoteState();

      if (state === null) {
        return;
      }

      // Notify update callbacks if the version has changed
      if (state.document !== null && state.document.version > this.lastVersion) {
        this.lastVersion = state.document.version;

        for (const cb of this.onUpdateCallbacks) {
          cb(state.document.state_vector, state.document.version);
        }
      }

      // Always notify session callbacks
      for (const cb of this.onSessionsCallbacks) {
        cb(state.sessions);
      }
    } catch {
      // Polling failures are silently ignored — the next poll will retry
    }
  }
}
