/**
 * Fine-grained reactivity primitive for Pulsar Admin Console.
 *
 * Hand-rolled signals system inspired by Solid.js and the TC39 Signals proposal.
 * Replaces external framework runtime for the admin SPA (Decision 2.8).
 *
 * @see https://github.com/tc39/proposal-signals
 */

/** @type {(() => void) | null} */
let currentEffect = null;

/**
 * Create a reactive signal holding a value.
 *
 * @template T
 * @param {T} initial
 * @returns {{ get(): T, set(next: T): void }}
 */
export function signal(initial) {
  let value = initial;
  /** @type {Set<() => void>} */
  const subscribers = new Set();

  return {
    get() {
      if (currentEffect) subscribers.add(currentEffect);
      return value;
    },
    set(next) {
      if (Object.is(next, value)) return;
      value = next;
      for (const fn of subscribers) fn();
    },
  };
}

/**
 * Create a derived value that recomputes when its signal dependencies change.
 *
 * @template T
 * @param {() => T} compute
 * @returns {{ get(): T }}
 */
export function derived(compute) {
  /** @type {ReturnType<typeof signal<T | undefined>>} */
  const s = signal(undefined);
  effect(() => s.set(compute()));
  return { get: () => /** @type {T} */ (s.get()) };
}

/**
 * Run a function and re-run it whenever its signal dependencies change.
 *
 * @param {() => void} fn
 */
export function effect(fn) {
  const run = () => {
    currentEffect = run;
    try {
      fn();
    } finally {
      currentEffect = null;
    }
  };
  run();
}
