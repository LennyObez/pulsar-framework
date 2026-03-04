/**
 * Pulsar WebRTC Client
 *
 * Manages peer-to-peer connections for audio/video calls using WebRTC.
 * Handles ICE candidate exchange, media stream management, and
 * connection lifecycle. Signaling is done via the messaging WebSocket.
 *
 * @module webrtc-client
 */
'use strict';

/**
 * @typedef {Object} WebRtcClientOptions
 * @property {PulsarMessagingClient} signalingClient - Messaging client for signaling
 * @property {RTCIceServer[]} iceServers - STUN/TURN server config
 * @property {string} userId - Local user ID
 */

class PulsarWebRtcClient {
  /** @type {RTCPeerConnection|null} */
  #peerConnection = null;

  /** @type {MediaStream|null} */
  #localStream = null;

  /** @type {MediaStream|null} */
  #remoteStream = null;

  /** @type {WebRtcClientOptions} */
  #options;

  /** @type {string|null} */
  #currentCallId = null;

  /** @type {string|null} */
  #remoteUserId = null;

  /** @type {Map<string, Function[]>} */
  #listeners = new Map();

  /** @type {RTCIceCandidate[]} */
  #pendingCandidates = [];

  /**
   * @param {WebRtcClientOptions} options
   */
  constructor(options) {
    this.#options = options;
    this.#setupSignalingListeners();
  }

  /**
   * Initiate a call to another user.
   * @param {string} callId - Unique call identifier
   * @param {string} remoteUserId - User to call
   * @param {MediaStreamConstraints} [constraints] - Media constraints
   * @returns {Promise<void>}
   */
  async call(callId, remoteUserId, constraints = { audio: true, video: true }) {
    this.#currentCallId = callId;
    this.#remoteUserId = remoteUserId;

    await this.#acquireMedia(constraints);
    this.#createPeerConnection();

    const offer = await this.#peerConnection.createOffer();
    await this.#peerConnection.setLocalDescription(offer);

    this.#sendSignaling('offer', {
      sdp: offer.sdp,
      type: offer.type,
    });

    this.#emit('calling', { callId, remoteUserId });
  }

  /**
   * Answer an incoming call.
   * @param {string} callId
   * @param {Object} offer - SDP offer from the caller
   * @param {MediaStreamConstraints} [constraints]
   * @returns {Promise<void>}
   */
  async answer(callId, offer, constraints = { audio: true, video: true }) {
    this.#currentCallId = callId;

    await this.#acquireMedia(constraints);
    this.#createPeerConnection();

    await this.#peerConnection.setRemoteDescription(new RTCSessionDescription(offer));

    // Apply any pending ICE candidates
    for (const candidate of this.#pendingCandidates) {
      await this.#peerConnection.addIceCandidate(candidate);
    }
    this.#pendingCandidates = [];

    const answer = await this.#peerConnection.createAnswer();
    await this.#peerConnection.setLocalDescription(answer);

    this.#sendSignaling('answer', {
      sdp: answer.sdp,
      type: answer.type,
    });

    this.#emit('call_connected', { callId });
  }

  /**
   * End the current call.
   */
  hangUp() {
    if (this.#currentCallId) {
      this.#sendSignaling('bye', { reason: 'user_hangup' });
    }

    this.#cleanup();
    this.#emit('call_ended', { callId: this.#currentCallId, reason: 'local_hangup' });
    this.#currentCallId = null;
    this.#remoteUserId = null;
  }

  /**
   * Toggle audio mute.
   * @returns {boolean} New mute state (true = muted)
   */
  toggleAudio() {
    if (!this.#localStream) return true;

    const audioTracks = this.#localStream.getAudioTracks();
    const newState = audioTracks.length > 0 ? !audioTracks[0].enabled : true;

    for (const track of audioTracks) {
      track.enabled = !newState;
    }

    this.#emit('audio_toggled', { muted: newState });
    return newState;
  }

  /**
   * Toggle video.
   * @returns {boolean} New state (true = video off)
   */
  toggleVideo() {
    if (!this.#localStream) return true;

    const videoTracks = this.#localStream.getVideoTracks();
    const newState = videoTracks.length > 0 ? !videoTracks[0].enabled : true;

    for (const track of videoTracks) {
      track.enabled = !newState;
    }

    this.#emit('video_toggled', { disabled: newState });
    return newState;
  }

  /**
   * Get the local media stream.
   * @returns {MediaStream|null}
   */
  get localStream() {
    return this.#localStream;
  }

  /**
   * Get the remote media stream.
   * @returns {MediaStream|null}
   */
  get remoteStream() {
    return this.#remoteStream;
  }

  /**
   * Whether a call is currently active.
   * @returns {boolean}
   */
  get inCall() {
    return this.#currentCallId !== null && this.#peerConnection !== null;
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
   * @param {MediaStreamConstraints} constraints
   * @returns {Promise<void>}
   */
  async #acquireMedia(constraints) {
    try {
      this.#localStream = await navigator.mediaDevices.getUserMedia(constraints);
      this.#emit('local_stream', { stream: this.#localStream });
    } catch (err) {
      this.#emit('media_error', { error: err.message });
      throw err;
    }
  }

  #createPeerConnection() {
    this.#peerConnection = new RTCPeerConnection({
      iceServers: this.#options.iceServers,
    });

    // Add local tracks to the connection
    if (this.#localStream) {
      for (const track of this.#localStream.getTracks()) {
        this.#peerConnection.addTrack(track, this.#localStream);
      }
    }

    // Handle incoming remote tracks
    this.#peerConnection.ontrack = (event) => {
      if (!this.#remoteStream) {
        this.#remoteStream = new MediaStream();
        this.#emit('remote_stream', { stream: this.#remoteStream });
      }
      this.#remoteStream.addTrack(event.track);
    };

    // Handle ICE candidates
    this.#peerConnection.onicecandidate = (event) => {
      if (event.candidate) {
        this.#sendSignaling('ice-candidate', {
          candidate: event.candidate.candidate,
          sdpMid: event.candidate.sdpMid,
          sdpMLineIndex: event.candidate.sdpMLineIndex,
        });
      }
    };

    // Connection state changes
    this.#peerConnection.onconnectionstatechange = () => {
      const state = this.#peerConnection?.connectionState;
      this.#emit('connection_state', { state });

      if (state === 'failed' || state === 'disconnected') {
        this.#emit('call_ended', {
          callId: this.#currentCallId,
          reason: 'connection_' + state,
        });
      }
    };

    // ICE connection state
    this.#peerConnection.oniceconnectionstatechange = () => {
      this.#emit('ice_state', {
        state: this.#peerConnection?.iceConnectionState,
      });
    };
  }

  #setupSignalingListeners() {
    this.#options.signalingClient.on('signaling', (data) => {
      if (!data || !data.type) return;

      switch (data.type) {
        case 'offer':
          this.#remoteUserId = data.from_user_id;
          this.#emit('incoming_call', {
            callId: data.call_id,
            from: data.from_user_id,
            offer: data.payload,
          });
          break;

        case 'answer':
          this.#handleAnswer(data.payload);
          break;

        case 'ice-candidate':
          this.#handleIceCandidate(data.payload);
          break;

        case 'bye':
          this.#cleanup();
          this.#emit('call_ended', {
            callId: data.call_id,
            reason: data.payload?.reason || 'remote_hangup',
          });
          this.#currentCallId = null;
          this.#remoteUserId = null;
          break;
      }
    });
  }

  /**
   * @param {Object} payload
   */
  async #handleAnswer(payload) {
    if (!this.#peerConnection) return;

    await this.#peerConnection.setRemoteDescription(new RTCSessionDescription(payload));

    // Apply pending candidates
    for (const candidate of this.#pendingCandidates) {
      await this.#peerConnection.addIceCandidate(candidate);
    }
    this.#pendingCandidates = [];

    this.#emit('call_connected', { callId: this.#currentCallId });
  }

  /**
   * @param {Object} payload
   */
  async #handleIceCandidate(payload) {
    const candidate = new RTCIceCandidate(payload);

    if (this.#peerConnection && this.#peerConnection.remoteDescription) {
      await this.#peerConnection.addIceCandidate(candidate);
    } else {
      this.#pendingCandidates.push(candidate);
    }
  }

  /**
   * @param {string} type
   * @param {Object} payload
   */
  #sendSignaling(type, payload) {
    this.#options.signalingClient.sendMessage({
      action: 'signaling',
      type,
      to_user_id: this.#remoteUserId,
      call_id: this.#currentCallId,
      payload,
    });
  }

  #cleanup() {
    if (this.#peerConnection) {
      this.#peerConnection.close();
      this.#peerConnection = null;
    }

    if (this.#localStream) {
      for (const track of this.#localStream.getTracks()) {
        track.stop();
      }
      this.#localStream = null;
    }

    this.#remoteStream = null;
    this.#pendingCandidates = [];
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
}

if (typeof module !== 'undefined' && module.exports) {
  module.exports = { PulsarWebRtcClient };
} else if (typeof window !== 'undefined') {
  window.PulsarWebRtcClient = PulsarWebRtcClient;
}
