/**
 * Type declarations for the managed-challenge proof-of-work core.
 */

/** Compute SHA-256 of a byte array, returning the 32-byte digest. */
export function sha256(bytes: Uint8Array): Uint8Array;

/** Count leading zero bits across a byte array. */
export function leadingZeroBits(bytes: Uint8Array): number;

/**
 * Find a solution string such that SHA-256(`${id}.${solution}`) has at least
 * `bits` leading zero bits, or null if none is found within `maxIterations`.
 */
export function solve(id: string, bits: number, maxIterations?: number): string | null;
