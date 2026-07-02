/**
 * Ambient declaration for CSS side-effect imports.
 *
 * The CMS frontend bundles CSS through esbuild (see `build.mjs`); the
 * `import '../styles/*.css'` statements in `main.ts` exist purely for their
 * bundler side effect and carry no TypeScript value. TypeScript 6 reports
 * TS2882 for side-effect imports of modules it cannot resolve, so we declare
 * the `*.css` module shape here to match the bundler's behaviour.
 */
declare module '*.css';
