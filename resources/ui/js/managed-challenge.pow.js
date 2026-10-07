/**
 * Pulsar Managed Challenge — Proof-of-Work core
 *
 * A synchronous SHA-256 and the proof-of-work search shared by the worker and
 * its tests. SubtleCrypto is intentionally NOT used here: it is asynchronous
 * and its per-call overhead makes a tight hashing loop orders of magnitude too
 * slow. A compact synchronous SHA-256 (FIPS 180-4) hashes hundreds of
 * thousands of candidates per second, which comfortably covers the configured
 * difficulty range.
 *
 * The hash must match the server byte-for-byte:
 * `SHA-256(id + '.' + solution)` over the UTF-8 bytes, with the solution
 * accepted when the digest has at least `bits` leading zero bits.
 *
 * @module managed-challenge.pow
 */
'use strict';

const K = new Uint32Array([
  0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
  0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
  0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
  0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
  0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
  0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
  0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
  0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
]);

/**
 * Rotate a 32-bit unsigned integer right by n bits.
 * @param {number} x
 * @param {number} n
 * @returns {number}
 */
function ror(x, n) {
  return ((x >>> n) | (x << (32 - n))) >>> 0;
}

/**
 * Compute SHA-256 of a byte array.
 * @param {Uint8Array} bytes
 * @returns {Uint8Array} 32-byte digest
 */
export function sha256(bytes) {
  const H = new Uint32Array([
    0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19,
  ]);

  const len = bytes.length;
  const bitLen = len * 8;
  const withOne = len + 1;
  const pad = (56 - (withOne % 64) + 64) % 64;
  const total = withOne + pad + 8;

  const msg = new Uint8Array(total);
  msg.set(bytes);
  msg[len] = 0x80;

  const dv = new DataView(msg.buffer);
  dv.setUint32(total - 8, Math.floor(bitLen / 0x100000000));
  dv.setUint32(total - 4, bitLen >>> 0);

  const w = new Uint32Array(64);

  for (let off = 0; off < total; off += 64) {
    for (let i = 0; i < 16; i++) {
      w[i] = dv.getUint32(off + i * 4);
    }
    for (let i = 16; i < 64; i++) {
      const s0 = ror(w[i - 15], 7) ^ ror(w[i - 15], 18) ^ (w[i - 15] >>> 3);
      const s1 = ror(w[i - 2], 17) ^ ror(w[i - 2], 19) ^ (w[i - 2] >>> 10);
      w[i] = (w[i - 16] + s0 + w[i - 7] + s1) >>> 0;
    }

    let a = H[0];
    let b = H[1];
    let c = H[2];
    let d = H[3];
    let e = H[4];
    let f = H[5];
    let g = H[6];
    let h = H[7];

    for (let i = 0; i < 64; i++) {
      const s1 = ror(e, 6) ^ ror(e, 11) ^ ror(e, 25);
      const ch = (e & f) ^ (~e & g);
      const t1 = (h + s1 + ch + K[i] + w[i]) >>> 0;
      const s0 = ror(a, 2) ^ ror(a, 13) ^ ror(a, 22);
      const maj = (a & b) ^ (a & c) ^ (b & c);
      const t2 = (s0 + maj) >>> 0;

      h = g;
      g = f;
      f = e;
      e = (d + t1) >>> 0;
      d = c;
      c = b;
      b = a;
      a = (t1 + t2) >>> 0;
    }

    H[0] = (H[0] + a) >>> 0;
    H[1] = (H[1] + b) >>> 0;
    H[2] = (H[2] + c) >>> 0;
    H[3] = (H[3] + d) >>> 0;
    H[4] = (H[4] + e) >>> 0;
    H[5] = (H[5] + f) >>> 0;
    H[6] = (H[6] + g) >>> 0;
    H[7] = (H[7] + h) >>> 0;
  }

  const out = new Uint8Array(32);
  const odv = new DataView(out.buffer);
  for (let i = 0; i < 8; i++) {
    odv.setUint32(i * 4, H[i]);
  }

  return out;
}

/**
 * Count leading zero bits across a byte array.
 * @param {Uint8Array} bytes
 * @returns {number}
 */
export function leadingZeroBits(bytes) {
  let count = 0;

  for (let i = 0; i < bytes.length; i++) {
    const byte = bytes[i];

    if (byte === 0) {
      count += 8;
      continue;
    }

    for (let mask = 0x80; mask > 0; mask >>= 1) {
      if ((byte & mask) !== 0) {
        return count;
      }
      count++;
    }

    return count;
  }

  return count;
}

/**
 * Find a solution string such that SHA-256(id + '.' + solution) has at least
 * `bits` leading zero bits. Returns null if no solution is found within
 * `maxIterations` (a safety bound; the configured difficulty stays well below).
 *
 * @param {string} id
 * @param {number} bits
 * @param {number} [maxIterations]
 * @returns {string|null}
 */
export function solve(id, bits, maxIterations = 1 << 26) {
  const encoder = new TextEncoder();

  for (let n = 0; n < maxIterations; n++) {
    if (leadingZeroBits(sha256(encoder.encode(id + '.' + n))) >= bits) {
      return String(n);
    }
  }

  return null;
}
