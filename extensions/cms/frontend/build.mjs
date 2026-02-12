import * as esbuild from 'esbuild';
import { existsSync, rmSync, mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const isProd = process.argv.includes('--prod');
const isWatch = process.argv.includes('--watch');

const frontendDir = join('extensions', 'cms', 'frontend');
const outdir = join(frontendDir, 'dist');

// Clean output directory
rmSync(outdir, { recursive: true, force: true });
mkdirSync(outdir, { recursive: true });

const timestamp = new Date().toISOString();

// Collect entry points — main bundle + standalone dark CSS
const entryPoints = {
  'cms-admin': join(frontendDir, 'src', 'main.ts'),
};

const darkCssPath = join(frontendDir, 'styles', 'cms-admin-dark.css');
if (existsSync(darkCssPath)) {
  entryPoints['cms-admin-dark'] = darkCssPath;
}

/** @type {esbuild.BuildOptions} */
const sharedOptions = {
  bundle: true,
  outdir,
  format: 'esm',
  target: ['es2024'],
  platform: 'browser',
  treeShaking: true,
  logLevel: 'info',
  metafile: isProd,
  banner: {
    js: `/* Pulsar CMS — built ${timestamp} */`,
    css: `/* Pulsar CMS — built ${timestamp} */`,
  },
};

/** @type {esbuild.BuildOptions} */
const buildOptions = {
  ...sharedOptions,
  entryPoints,
  ...(isProd
    ? {
        minify: true,
        sourcemap: false,
        drop: ['debugger', 'console'],
        entryNames: '[name]-[hash]',
      }
    : {
        minify: false,
        sourcemap: true,
      }),
};

try {
  if (isWatch) {
    const ctx = await esbuild.context(buildOptions);
    await ctx.watch();
    console.log('[cms] watching for changes...');
  } else {
    const result = await esbuild.build(buildOptions);

    // In production, write a manifest mapping original names to hashed filenames
    if (isProd && result.metafile) {
      const manifest = {};
      for (const [outputPath, meta] of Object.entries(result.metafile.outputs)) {
        if (meta.entryPoint) {
          const entryName = meta.entryPoint;
          const filename = outputPath.split('/').pop();
          manifest[entryName] = filename;
        }
      }
      writeFileSync(join(outdir, 'manifest.json'), JSON.stringify(manifest, null, 2));
    }
  }
} catch (error) {
  console.error('[cms] Build failed:', error.message || error);
  process.exit(1);
}
