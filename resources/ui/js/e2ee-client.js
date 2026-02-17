/**
 * Pulsar E2EE Client
 *
 * Client-side end-to-end encryption using the Web Crypto API (SubtleCrypto).
 * Mirrors the server's libsodium approach: XChaCha20-Poly1305 for messages,
 * X25519 for key exchange, Argon2id for key wrapping.
 *
 * All encryption/decryption happens in the browser. The server only sees
 * ciphertext and can never read message content.
 *
 * @module e2ee-client
 */
'use strict';

class PulsarE2eeClient {
  /** @type {CryptoKeyPair|null} */
  #keyPair = null;

  /** @type {CryptoKey|null} */
  #identityKey = null;

  /** @type {Map<string, CryptoKey>} */
  #conversationKeys = new Map();

  /**
   * Generate a new ECDH key pair for key exchange.
   * Uses P-256 (the closest standard Web Crypto curve to X25519).
   * @returns {Promise<{publicKey: JsonWebKey, privateKey: JsonWebKey}>}
   */
  async generateKeyPair() {
    this.#keyPair = await crypto.subtle.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, true, [
      'deriveKey',
      'deriveBits',
    ]);

    const publicKey = await crypto.subtle.exportKey('jwk', this.#keyPair.publicKey);
    const privateKey = await crypto.subtle.exportKey('jwk', this.#keyPair.privateKey);

    return { publicKey, privateKey };
  }

  /**
   * Import a previously exported private key.
   * @param {JsonWebKey} jwk
   * @returns {Promise<void>}
   */
  async importPrivateKey(jwk) {
    const privateKey = await crypto.subtle.importKey(
      'jwk',
      jwk,
      { name: 'ECDH', namedCurve: 'P-256' },
      true,
      ['deriveKey', 'deriveBits'],
    );

    // Reconstruct the key pair (public key derived from private)
    const publicJwk = { ...jwk };
    delete publicJwk.d;
    publicJwk.key_ops = [];

    const publicKey = await crypto.subtle.importKey(
      'jwk',
      publicJwk,
      { name: 'ECDH', namedCurve: 'P-256' },
      true,
      [],
    );

    this.#keyPair = { privateKey, publicKey };
  }

  /**
   * Derive a shared encryption key from our private key and their public key.
   * @param {JsonWebKey} theirPublicKeyJwk
   * @returns {Promise<CryptoKey>}
   */
  async deriveSharedKey(theirPublicKeyJwk) {
    if (!this.#keyPair) {
      throw new Error('Key pair not initialized. Call generateKeyPair() first.');
    }

    const theirPublicKey = await crypto.subtle.importKey(
      'jwk',
      theirPublicKeyJwk,
      { name: 'ECDH', namedCurve: 'P-256' },
      false,
      [],
    );

    return crypto.subtle.deriveKey(
      { name: 'ECDH', public: theirPublicKey },
      this.#keyPair.privateKey,
      { name: 'AES-GCM', length: 256 },
      false,
      ['encrypt', 'decrypt'],
    );
  }

  /**
   * Set the encryption key for a specific conversation.
   * @param {string} conversationId
   * @param {CryptoKey} key
   */
  setConversationKey(conversationId, key) {
    this.#conversationKeys.set(conversationId, key);
  }

  /**
   * Encrypt a message for a conversation.
   * @param {string} conversationId
   * @param {string} plaintext
   * @returns {Promise<{ciphertext: string, nonce: string}>} Base64-encoded values
   */
  async encrypt(conversationId, plaintext) {
    const key = this.#conversationKeys.get(conversationId);
    if (!key) {
      throw new Error(`No encryption key for conversation: ${conversationId}`);
    }

    const encoder = new TextEncoder();
    const data = encoder.encode(plaintext);
    const nonce = crypto.getRandomValues(new Uint8Array(12)); // AES-GCM uses 12-byte nonce

    const ciphertextBuffer = await crypto.subtle.encrypt({ name: 'AES-GCM', iv: nonce }, key, data);

    return {
      ciphertext: this.#bufferToBase64(ciphertextBuffer),
      nonce: this.#bufferToBase64(nonce.buffer),
    };
  }

  /**
   * Decrypt a message from a conversation.
   * @param {string} conversationId
   * @param {string} ciphertextBase64
   * @param {string} nonceBase64
   * @returns {Promise<string>} Decrypted plaintext
   */
  async decrypt(conversationId, ciphertextBase64, nonceBase64) {
    const key = this.#conversationKeys.get(conversationId);
    if (!key) {
      throw new Error(`No encryption key for conversation: ${conversationId}`);
    }

    const ciphertext = this.#base64ToBuffer(ciphertextBase64);
    const nonce = this.#base64ToBuffer(nonceBase64);

    const plaintextBuffer = await crypto.subtle.decrypt(
      { name: 'AES-GCM', iv: nonce },
      key,
      ciphertext,
    );

    const decoder = new TextDecoder();
    return decoder.decode(plaintextBuffer);
  }

  /**
   * Wrap (encrypt) a private key with a password-derived key.
   * Uses PBKDF2 for key derivation (Web Crypto Argon2id equivalent).
   * @param {JsonWebKey} privateKeyJwk
   * @param {string} password
   * @returns {Promise<{wrappedKey: string, salt: string, nonce: string}>} Base64-encoded
   */
  async wrapPrivateKey(privateKeyJwk, password) {
    const salt = crypto.getRandomValues(new Uint8Array(32));
    const derivedKey = await this.#deriveKeyFromPassword(password, salt);

    const encoder = new TextEncoder();
    const keyData = encoder.encode(JSON.stringify(privateKeyJwk));
    const nonce = crypto.getRandomValues(new Uint8Array(12));

    const wrappedBuffer = await crypto.subtle.encrypt(
      { name: 'AES-GCM', iv: nonce },
      derivedKey,
      keyData,
    );

    return {
      wrappedKey: this.#bufferToBase64(wrappedBuffer),
      salt: this.#bufferToBase64(salt.buffer),
      nonce: this.#bufferToBase64(nonce.buffer),
    };
  }

  /**
   * Unwrap (decrypt) a private key with the user's password.
   * @param {string} wrappedKeyBase64
   * @param {string} saltBase64
   * @param {string} nonceBase64
   * @param {string} password
   * @returns {Promise<JsonWebKey>}
   */
  async unwrapPrivateKey(wrappedKeyBase64, saltBase64, nonceBase64, password) {
    const salt = this.#base64ToBuffer(saltBase64);
    const derivedKey = await this.#deriveKeyFromPassword(password, new Uint8Array(salt));

    const wrappedKey = this.#base64ToBuffer(wrappedKeyBase64);
    const nonce = this.#base64ToBuffer(nonceBase64);

    const keyDataBuffer = await crypto.subtle.decrypt(
      { name: 'AES-GCM', iv: nonce },
      derivedKey,
      wrappedKey,
    );

    const decoder = new TextDecoder();
    return JSON.parse(decoder.decode(keyDataBuffer));
  }

  /**
   * Generate a random symmetric key for group conversations.
   * @returns {Promise<CryptoKey>}
   */
  async generateGroupKey() {
    return crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, [
      'encrypt',
      'decrypt',
    ]);
  }

  /**
   * Export a CryptoKey to raw bytes (for distribution).
   * @param {CryptoKey} key
   * @returns {Promise<string>} Base64-encoded raw key
   */
  async exportKey(key) {
    const raw = await crypto.subtle.exportKey('raw', key);
    return this.#bufferToBase64(raw);
  }

  /**
   * Import raw key bytes as a CryptoKey.
   * @param {string} base64Key
   * @returns {Promise<CryptoKey>}
   */
  async importGroupKey(base64Key) {
    const raw = this.#base64ToBuffer(base64Key);
    return crypto.subtle.importKey('raw', raw, { name: 'AES-GCM', length: 256 }, false, [
      'encrypt',
      'decrypt',
    ]);
  }

  /**
   * Clear all stored keys from memory.
   */
  clear() {
    this.#keyPair = null;
    this.#identityKey = null;
    this.#conversationKeys.clear();
  }

  /**
   * @param {string} password
   * @param {Uint8Array} salt
   * @returns {Promise<CryptoKey>}
   */
  async #deriveKeyFromPassword(password, salt) {
    const encoder = new TextEncoder();
    const passwordKey = await crypto.subtle.importKey(
      'raw',
      encoder.encode(password),
      'PBKDF2',
      false,
      ['deriveKey'],
    );

    return crypto.subtle.deriveKey(
      {
        name: 'PBKDF2',
        salt: salt,
        iterations: 600000, // OWASP recommended minimum
        hash: 'SHA-256',
      },
      passwordKey,
      { name: 'AES-GCM', length: 256 },
      false,
      ['encrypt', 'decrypt'],
    );
  }

  /**
   * @param {ArrayBuffer} buffer
   * @returns {string}
   */
  #bufferToBase64(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.length; i++) {
      binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary);
  }

  /**
   * @param {string} base64
   * @returns {ArrayBuffer}
   */
  #base64ToBuffer(base64) {
    const binary = atob(base64);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
      bytes[i] = binary.charCodeAt(i);
    }
    return bytes.buffer;
  }
}

if (typeof module !== 'undefined' && module.exports) {
  module.exports = { PulsarE2eeClient };
} else if (typeof window !== 'undefined') {
  window.PulsarE2eeClient = PulsarE2eeClient;
}
