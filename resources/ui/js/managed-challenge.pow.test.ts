/**
 * Pulsar Managed Challenge — Proof-of-Work core tests
 *
 * Verifies the synchronous SHA-256 byte-for-byte against node:crypto (and thus
 * against the server's hash('sha256')), the leading-zero-bit counter, and that
 * solve() produces a solution the predicate accepts.
 */
import { describe, it, expect } from 'vitest';
import { createHash } from 'node:crypto';
import { sha256, leadingZeroBits, solve } from './managed-challenge.pow.js';

function reference(input: string): Uint8Array {
  return new Uint8Array(createHash('sha256').update(Buffer.from(input, 'utf8')).digest());
}

describe('sha256', () => {
  const inputs = [
    '',
    'a',
    'abc',
    'hello.world',
    'café',
    '日本語のテスト',
    '0123456789abcdef0123456789abcdef.12345',
    'x'.repeat(55), // one byte short of a block boundary
    'y'.repeat(56), // forces an extra padding block
    'z'.repeat(200),
  ];

  it.each(inputs)('matches node:crypto for %j', (input) => {
    const got = sha256(new TextEncoder().encode(input));
    expect(Array.from(got)).toEqual(Array.from(reference(input)));
  });

  it('produces a 32-byte digest', () => {
    expect(sha256(new TextEncoder().encode('anything')).length).toBe(32);
  });
});

describe('leadingZeroBits', () => {
  it('counts whole zero bytes', () => {
    expect(leadingZeroBits(new Uint8Array([0x00, 0x00, 0xff]))).toBe(16);
  });

  it('counts partial leading zeros in a byte', () => {
    expect(leadingZeroBits(new Uint8Array([0x00, 0x80]))).toBe(8);
    expect(leadingZeroBits(new Uint8Array([0x0f]))).toBe(4);
    expect(leadingZeroBits(new Uint8Array([0x01]))).toBe(7);
  });

  it('returns 0 when the first bit is set', () => {
    expect(leadingZeroBits(new Uint8Array([0xff, 0x00]))).toBe(0);
  });

  it('returns 0 for an empty array', () => {
    expect(leadingZeroBits(new Uint8Array([]))).toBe(0);
  });
});

describe('solve', () => {
  it('finds a solution meeting the difficulty', () => {
    const id = 'abcdef0123456789abcdef0123456789';
    const bits = 10;

    const solution = solve(id, bits);

    expect(solution).not.toBeNull();
    const digest = sha256(new TextEncoder().encode(`${id}.${solution}`));
    expect(leadingZeroBits(digest)).toBeGreaterThanOrEqual(bits);
    // Cross-check the hash against the reference implementation.
    expect(Array.from(digest)).toEqual(Array.from(reference(`${id}.${solution}`)));
  });

  it('returns null when no solution is found within the bound', () => {
    expect(solve('deadbeef', 32, 5)).toBeNull();
  });
});
