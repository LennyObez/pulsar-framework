/**
 * Pulsar Messaging WebSocket Client
 *
 * Handles real-time message delivery over WebSocket with automatic
 * reconnection and offline message queuing. Messages are encrypted
 * client-side before transmission using the E2EE module.
 *
 * @module messaging-client
 */
'use strict';

/**
 * @typedef {Object} MessagingClientOptions
 * @property {string} url - WebSocket server URL
 * @property {string} token - Authentication token
 * @property {number} [reconnectInterval=3000] - Reconnection delay in ms
 * @property {number} [maxReconnectAttempts=10] - Max reconnection attempts
 * @property {number} [heartbeatInterval=30000] - Heartbeat interval in ms
 */

/**
 * @typedef {Object} MessagePayload
 * @property {string} conversation_id
 * @property {string} encrypted_content - Base64-encoded ciphertext
 * @property {string} nonce - Base64-encoded nonce
 * @property {string} [type='text']
 */

class PulsarMessagingClient {
  /** @type {WebSocket|null} */
  #ws = null;

  /** @type {MessagingClientOptions} */
  #options;

  /** @type {number} */
  #reconnectAttempts = 0;

  /** @type {MessagePayload[]} */
  #messageQueue = [];

  /** @type {Map<string, Function[]>} */
  #listeners = new Map();

  /** @type {number|null} */
  #heartbeatTimer = null;

  /** @type {number|null} */
  #reconnectTimer = null;

  /** @type {boolean} */
  #intentionalClose = false;

  /**
   * @param {MessagingClientOptions} options
   */
  constructor(options) {
    this.#options = {
      reconnectInterval: 3000,
      maxReconnectAttempts: 10,
      heartbeatInterval: 30000,
      ...options,
    };
  }

  /**
   * Connect to the WebSocket server.
   * @returns {Promise<void>}
   */
  connect() {
    return new Promise((resolve, reject) => {
      this.#intentionalClose = false;

      try {
        this.#ws = new WebSocket(this.#options.url);
      } catch (err) {
        reject(err);
        return;
      }

      this.#ws.onopen = () => {
        this.#reconnectAttempts = 0;
        this.#startHeartbeat();
        this.#flushQueue();
        this.#emit('connected', null);
        resolve();
      };

      this.#ws.onmessage = (event) => {
        this.#handleMessage(event.data);
      };

      this.#ws.onclose = (event) => {
        this.#stopHeartbeat();
        this.#emit('disconnected', { code: event.code, reason: event.reason });

        if (!this.#intentionalClose) {
          this.#scheduleReconnect();
        }
      };

      this.#ws.onerror = () => {
        this.#emit('error', { message: 'WebSocket connection error' });
      };
    });
  }

  /**
   * Disconnect from the server.
   */
  disconnect() {
    this.#intentionalClose = true;
    this.#stopHeartbeat();

    if (this.#reconnectTimer !== null) {
      clearTimeout(this.#reconnectTimer);
      this.#reconnectTimer = null;
    }

    if (this.#ws) {
      this.#ws.close(1000, 'Client disconnect');
      this.#ws = null;
    }
  }

  /**
   * Send an encrypted message.
   * @param {MessagePayload} payload
   */
  sendMessage(payload) {
    const message = JSON.stringify({
      action: 'send_message',
      ...payload,
    });

    if (this.#isConnected()) {
      this.#ws.send(message);
    } else {
      this.#messageQueue.push(payload);
    }
  }

  /**
   * Subscribe to a conversation channel.
   * @param {string} conversationId
   */
  subscribe(conversationId) {
    this.#send({
      action: 'subscribe',
      conversation_id: conversationId,
    });
  }

  /**
   * Mark messages in a conversation as read.
   * @param {string} conversationId
   */
  markRead(conversationId) {
    this.#send({
      action: 'mark_read',
      conversation_id: conversationId,
    });
  }

  /**
   * Send typing indicator.
   * @param {string} conversationId
   * @param {boolean} isTyping
   */
  sendTyping(conversationId, isTyping) {
    this.#send({
      action: isTyping ? 'typing_start' : 'typing_stop',
      conversation_id: conversationId,
    });
  }

  /**
   * Register an event listener.
   * @param {string} event
   * @param {Function} callback
   */
  on(event, callback) {
    if (!this.#listeners.has(event)) {
      this.#listeners.set(event, []);
    }
    this.#listeners.get(event).push(callback);
  }

  /**
   * Remove an event listener.
   * @param {string} event
   * @param {Function} callback
   */
  off(event, callback) {
    const listeners = this.#listeners.get(event);
    if (listeners) {
      this.#listeners.set(
        event,
        listeners.filter((fn) => fn !== callback),
      );
    }
  }

  /**
   * Whether the client is currently connected.
   * @returns {boolean}
   */
  get connected() {
    return this.#isConnected();
  }

  /**
   * Number of messages waiting in the offline queue.
   * @returns {number}
   */
  get queueSize() {
    return this.#messageQueue.length;
  }

  /** @param {string} rawData */
  #handleMessage(rawData) {
    let parsed;

    try {
      parsed = JSON.parse(rawData);
    } catch {
      return;
    }

    const event = parsed.event || 'unknown';
    const data = parsed.data || parsed;

    this.#emit(event, data);
  }

  #startHeartbeat() {
    this.#heartbeatTimer = setInterval(() => {
      if (this.#isConnected()) {
        this.#send({ action: 'ping' });
      }
    }, this.#options.heartbeatInterval);
  }

  #stopHeartbeat() {
    if (this.#heartbeatTimer !== null) {
      clearInterval(this.#heartbeatTimer);
      this.#heartbeatTimer = null;
    }
  }

  #scheduleReconnect() {
    if (this.#reconnectAttempts >= this.#options.maxReconnectAttempts) {
      this.#emit('reconnect_failed', {
        attempts: this.#reconnectAttempts,
      });
      return;
    }

    const delay = this.#options.reconnectInterval * Math.pow(1.5, this.#reconnectAttempts);

    this.#reconnectTimer = setTimeout(() => {
      this.#reconnectAttempts++;
      this.#emit('reconnecting', { attempt: this.#reconnectAttempts });
      this.connect().catch(() => {});
    }, delay);
  }

  #flushQueue() {
    while (this.#messageQueue.length > 0 && this.#isConnected()) {
      const payload = this.#messageQueue.shift();
      this.sendMessage(payload);
    }
  }

  /** @param {Object} data */
  #send(data) {
    if (this.#isConnected()) {
      this.#ws.send(JSON.stringify(data));
    }
  }

  /**
   * @param {string} event
   * @param {any} data
   */
  #emit(event, data) {
    const listeners = this.#listeners.get(event) || [];
    for (const fn of listeners) {
      try {
        fn(data);
      } catch {
        // Listener errors should not break the client
      }
    }
  }

  /** @returns {boolean} */
  #isConnected() {
    return this.#ws !== null && this.#ws.readyState === WebSocket.OPEN;
  }
}

// Export for module systems, expose globally for script tags
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { PulsarMessagingClient };
} else if (typeof window !== 'undefined') {
  window.PulsarMessagingClient = PulsarMessagingClient;
}
