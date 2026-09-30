import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    globals: false,
    environment: 'node',
    // .mjs is included for tools/ci gates that must run without a type-declaration
    // package: the bundled-font gate needs node:zlib's Brotli to read a WOFF2, and this
    // toolchain has no @types/node, so writing it as TypeScript would fail `pnpm
    // typecheck`. A gate that needs a new dependency to run is a gate that gets skipped.
    include: ['**/*.test.ts', '**/*.spec.ts', '**/*.test.mjs'],
    exclude: ['node_modules', 'vendor', 'dist', 'coverage'],
    coverage: {
      provider: 'v8',
      reporter: ['text', 'json', 'html'],
      exclude: ['node_modules', 'vendor', 'dist', 'coverage'],
    },
  },
});
