/**
 * Pulsar Echo — lightweight WebSocket client for real-time broadcasting.
 *
 * Usage:
 *   const echo = new PulsarEcho({ host: 'localhost', port: 6001 });
 *   echo.channel('orders').listen('OrderUpdated', (data) => { ... });
 *   echo.private('chat.room.1').listen('MessageSent', (data) => { ... });
 *   echo.presence('editors').here((users) => { ... });
 */

interface PulsarEchoConfig {
  host: string;
  port: number;
  path?: string;
  secure?: boolean;
  authToken?: string;
  autoReconnect?: boolean;
  reconnectDelayMs?: number;
  maxReconnectAttempts?: number;
  heartbeatIntervalMs?: number;
}

interface ChannelSubscription {
  channel: string;
  listeners: Map<string, Array<(data: Record<string, unknown>) => void>>;
}

interface ServerMessage {
  event: string;
  channel?: string;
  data: Record<string, unknown>;
}

type PresenceCallback = (members: Array<Record<string, unknown>>) => void;
type JoinCallback = (member: Record<string, unknown>) => void;
type LeaveCallback = (member: Record<string, unknown>) => void;

class PulsarChannel {
  private readonly subscription: ChannelSubscription;

  constructor(
    private readonly echo: PulsarEcho,
    readonly name: string,
  ) {
    this.subscription = { channel: name, listeners: new Map() };
  }

  listen(event: string, callback: (data: Record<string, unknown>) => void): this {
    const existing = this.subscription.listeners.get(event) ?? [];
    existing.push(callback);
    this.subscription.listeners.set(event, existing);
    return this;
  }

  stopListening(event: string): this {
    this.subscription.listeners.delete(event);
    return this;
  }

  dispatch(event: string, data: Record<string, unknown>): void {
    const listeners = this.subscription.listeners.get(event);
    if (listeners) {
      for (const listener of listeners) {
        listener(data);
      }
    }
  }

  leave(): void {
    this.echo.leave(this.name);
  }
}

class PulsarPresenceChannel extends PulsarChannel {
  private hereCallback: PresenceCallback | null = null;
  private joinCallback: JoinCallback | null = null;
  private leaveCallback: LeaveCallback | null = null;

  here(callback: PresenceCallback): this {
    this.hereCallback = callback;
    return this;
  }

  joining(callback: JoinCallback): this {
    this.joinCallback = callback;
    return this;
  }

  leaving(callback: LeaveCallback): this {
    this.leaveCallback = callback;
    return this;
  }

  handlePresenceEvent(event: string, data: Record<string, unknown>): void {
    switch (event) {
      case 'pusher:subscription_succeeded':
        if (this.hereCallback && Array.isArray(data.members)) {
          this.hereCallback(data.members as Array<Record<string, unknown>>);
        }
        break;
      case 'pusher:member_added':
        if (this.joinCallback) {
          this.joinCallback(data);
        }
        break;
      case 'pusher:member_removed':
        if (this.leaveCallback) {
          this.leaveCallback(data);
        }
        break;
      default:
        this.dispatch(event, data);
    }
  }
}

class PulsarEcho {
  private ws: WebSocket | null = null;
  private readonly channels: Map<string, PulsarChannel> = new Map();
  private reconnectAttempts = 0;
  private reconnectTimer: ReturnType<typeof setTimeout> | null = null;
  private heartbeatTimer: ReturnType<typeof setInterval> | null = null;
  private readonly config: Required<PulsarEchoConfig>;

  constructor(config: PulsarEchoConfig) {
    this.config = {
      path: '/ws',
      secure: false,
      authToken: '',
      autoReconnect: true,
      reconnectDelayMs: 1000,
      maxReconnectAttempts: 10,
      heartbeatIntervalMs: 30_000,
      ...config,
    };
  }

  connect(): void {
    const protocol = this.config.secure ? 'wss' : 'ws';
    const url = `${protocol}://${this.config.host}:${this.config.port}${this.config.path}`;

    this.ws = new WebSocket(url);

    this.ws.onopen = (): void => {
      this.reconnectAttempts = 0;
      this.startHeartbeat();

      // Re-subscribe to existing channels after reconnect
      for (const name of this.channels.keys()) {
        this.sendSubscribe(name);
      }
    };

    this.ws.onmessage = (event: MessageEvent): void => {
      this.handleMessage(String(event.data));
    };

    this.ws.onclose = (): void => {
      this.stopHeartbeat();

      if (this.config.autoReconnect) {
        this.scheduleReconnect();
      }
    };

    this.ws.onerror = (): void => {
      // Error handling — reconnect will be triggered by onclose
    };
  }

  disconnect(): void {
    this.config.autoReconnect = false;

    if (this.reconnectTimer !== null) {
      clearTimeout(this.reconnectTimer);
      this.reconnectTimer = null;
    }

    this.stopHeartbeat();
    this.ws?.close(1000, 'Client disconnect');
    this.ws = null;
  }

  channel(name: string): PulsarChannel {
    const existing = this.channels.get(name);

    if (existing) {
      return existing;
    }

    const channel = new PulsarChannel(this, name);
    this.channels.set(name, channel);
    this.sendSubscribe(name);

    return channel;
  }

  private(name: string): PulsarChannel {
    return this.channel(`private-${name}`);
  }

  presence(name: string): PulsarPresenceChannel {
    const fullName = `presence-${name}`;
    const existing = this.channels.get(fullName);

    if (existing instanceof PulsarPresenceChannel) {
      return existing;
    }

    const channel = new PulsarPresenceChannel(this, fullName);
    this.channels.set(fullName, channel);
    this.sendSubscribe(fullName);

    return channel;
  }

  leave(channelName: string): void {
    this.channels.delete(channelName);
    this.sendUnsubscribe(channelName);
  }

  private handleMessage(raw: string): void {
    let message: ServerMessage;

    try {
      message = JSON.parse(raw) as ServerMessage;
    } catch {
      return;
    }

    const channelName = message.channel ?? '';
    const channel = this.channels.get(channelName);

    if (!channel) {
      return;
    }

    if (channel instanceof PulsarPresenceChannel) {
      channel.handlePresenceEvent(message.event, message.data);
    } else {
      channel.dispatch(message.event, message.data);
    }
  }

  private sendSubscribe(channel: string): void {
    this.send({
      event: 'pushar:subscribe',
      data: {
        channel,
        auth: this.config.authToken,
      },
    });
  }

  private sendUnsubscribe(channel: string): void {
    this.send({
      event: 'pushar:unsubscribe',
      data: { channel },
    });
  }

  private send(message: Record<string, unknown>): void {
    if (this.ws?.readyState === WebSocket.OPEN) {
      this.ws.send(JSON.stringify(message));
    }
  }

  private startHeartbeat(): void {
    this.heartbeatTimer = setInterval(() => {
      this.send({ event: 'pushar:ping' });
    }, this.config.heartbeatIntervalMs);
  }

  private stopHeartbeat(): void {
    if (this.heartbeatTimer !== null) {
      clearInterval(this.heartbeatTimer);
      this.heartbeatTimer = null;
    }
  }

  private scheduleReconnect(): void {
    if (this.reconnectAttempts >= this.config.maxReconnectAttempts) {
      return;
    }

    const delay = this.config.reconnectDelayMs * Math.pow(2, this.reconnectAttempts);
    this.reconnectAttempts++;

    this.reconnectTimer = setTimeout(() => {
      this.connect();
    }, delay);
  }
}

export { PulsarEcho, PulsarChannel, PulsarPresenceChannel };
export type {
  PulsarEchoConfig,
  ChannelSubscription,
  ServerMessage,
  PresenceCallback,
  JoinCallback,
  LeaveCallback,
};
