/**
 * Watches the bundled-font gate refuse each defect it exists to catch.
 *
 * ADR-0060: a check never observed to fail is indistinguishable from no check. That is
 * not an abstraction here — the defect this gate was written for shipped for months
 * behind a green build, because the only thing anybody had ever done to
 * `jetbrains-mono-variable.woff2` was look at its name.
 *
 * So the first plant below is that exact file. It is committed under
 * tests/Unit/Integrity/Fixture/fonts/ as the latin-ext slice it always was, and the
 * manifest is re-stated around it so that its size and hash are honest — meaning nothing
 * but the coverage rule can catch it. That is the real test: a font that is correctly
 * declared, structurally perfect, and cannot draw a digit.
 *
 * The faces are now split by script, and the split brings its own way of lying: a
 * `unicode-range` is a promise about a file, and a browser that believes an over-wide one
 * stops looking and paints the fallback. Four plants below are about that promise — a
 * range too wide, a range too narrow, a rule with no range at all, and a bundle whose
 * split has been undone so that every reader pays for every script again.
 *
 * Every tree is planted under the system temp root, never in the working tree, and the
 * removal is asserted rather than attempted: a fixture that outlives the test is the
 * failure mode this whole file argues against.
 */

import { createHash } from 'node:crypto';
import {
  cpSync,
  existsSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  readdirSync,
  rmSync,
  writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { afterEach, describe, expect, it } from 'vitest';

import { auditBundledFonts } from './verify-bundled-fonts.mjs';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');

/** The code face as it actually shipped: 190 codepoints, no digits, no a-z. */
const SHIPPED_SLICE = join(
  ROOT,
  'tests/Unit/Integrity/Fixture/fonts/jetbrains-mono-latin-ext-slice.woff2',
);

/** The subset every reader downloads, whatever language the page is in. */
const FIRST_PAINT = 'jetbrains-mono-variable-latin.woff2';

/** @type {string[]} */
const planted = [];

afterEach(() => {
  while (planted.length > 0) {
    const tree = planted.pop();

    rmSync(tree, { recursive: true, force: true });
    expect(existsSync(tree), 'a planted fixture tree survived the test').toBe(false);
  }
});

/**
 * Copies everything the audit reads into a fresh tree outside the repository.
 *
 * @returns {string} the planted root
 */
function plantTree() {
  const tree = mkdtempSync(join(tmpdir(), 'pulsar-fonts-'));

  planted.push(tree);

  const manifest = readManifest(ROOT);
  const sources = [
    manifest.fontDirectory,
    manifest.styleSheet,
    ...manifest.localeSources,
    ...manifest.styleSheetSources,
  ];

  for (const path of sources) {
    for (const source of expand(path)) {
      cpSync(join(ROOT, source), join(tree, source), { recursive: true });
    }
  }

  return tree;
}

/**
 * @param {string} pattern a path with at most one `*` segment, repository-relative
 * @returns {string[]}
 */
function expand(pattern) {
  if (!pattern.includes('*')) {
    return [pattern];
  }

  const [head, tail] = pattern.split('*');
  const parent = (head ?? '').replace(/\/$/, '');

  return readdirNames(join(ROOT, parent))
    .map((entry) => `${parent}/${entry}${tail ?? ''}`)
    .filter((path) => existsSync(join(ROOT, path)));
}

/**
 * @param {string} directory
 * @returns {string[]}
 */
function readdirNames(directory) {
  return existsSync(directory) ? readdirSync(directory) : [];
}

/**
 * @param {string} root
 * @returns {any}
 */
function readManifest(root) {
  return JSON.parse(readFileSync(join(root, 'resources/ui/fonts/fonts.manifest.json'), 'utf8'));
}

/**
 * @param {string} tree
 * @param {(manifest: any) => void} edit
 */
function editManifest(tree, edit) {
  const path = join(tree, 'resources/ui/fonts/fonts.manifest.json');
  const manifest = JSON.parse(readFileSync(path, 'utf8'));

  edit(manifest);
  writeFileSync(path, `${JSON.stringify(manifest, null, 2)}\n`);
}

/**
 * Every subset entry in a planted manifest, flattened.
 *
 * @param {any} manifest
 * @returns {any[]}
 */
function subsetsOf(manifest) {
  return manifest.families.flatMap((family) => family.faces.flatMap((face) => face.subsets));
}

/**
 * @param {string} tree
 * @returns {string}
 */
function styleSheetPath(tree) {
  return join(tree, 'resources/ui/css/tokens.css');
}

/**
 * Rewrites the `unicode-range` of the one @font-face rule that serves a given file.
 *
 * Editing the declaration rather than the file is the point: the bytes stay exactly what
 * the manifest verified, so only a rule that reads the file back can notice.
 *
 * @param {string} tree
 * @param {string} file basename of the woff2 the rule serves
 * @param {string | null} value the new range, or null to delete the declaration
 */
function editUnicodeRange(tree, file, value) {
  const path = styleSheetPath(tree);
  const css = readFileSync(path, 'utf8');
  const block = new RegExp(`@font-face \\{[^}]*${file.replace(/\./g, '\\.')}[^}]*\\}`);
  const match = block.exec(css);

  expect(match, `no @font-face rule in the planted tree serves ${file}`).not.toBeNull();

  const rewritten = (match?.[0] ?? '').replace(
    /\n\s*unicode-range:[^;]+;/,
    value === null ? '' : `\n  unicode-range: ${value};`,
  );

  writeFileSync(path, css.replace(block, rewritten), 'utf8');
}

/**
 * @param {string} tree
 * @param {string} rule
 * @returns {import('./verify-bundled-fonts.mjs').Violation[]}
 */
function violationsOf(tree, rule) {
  return auditBundledFonts(tree).violations.filter((violation) => violation.rule === rule);
}

describe('the bundled fonts themselves', () => {
  it('cover every role and every character the shipped translations use', () => {
    const audit = auditBundledFonts(ROOT);

    expect(
      audit.violations,
      audit.violations.map((v) => `[${v.rule}] ${v.subject}: ${v.message}`).join('\n'),
    ).toEqual([]);

    // A vacuity floor: with no files read, every assertion above passes over nothing,
    // which is the shape of the failure this file exists to refuse.
    expect(audit.subsets.length).toBeGreaterThan(5);
    expect(audit.locales.length).toBeGreaterThan(0);
  });

  it('cost a Latin reader a fraction of the bundle they replaced', () => {
    const audit = auditBundledFonts(ROOT);

    // Not a restatement of the budget rule: that one refuses a regression past the
    // ceiling, and this one refuses the split quietly becoming pointless — every subset
    // downloaded on every page would satisfy the ceiling only until the bundle grew.
    expect(audit.firstPaintBytes).toBeLessThan(audit.totalBytes / 2);
    expect(audit.firstPaintBytes).toBeLessThan(audit.firstPaintCeiling);
  });
});

describe('the gate refuses', () => {
  it('a subset slice shipped as the code face, declared honestly', () => {
    const tree = plantTree();
    const slice = readFileSync(SHIPPED_SLICE);

    writeFileSync(join(tree, 'resources/ui/fonts', FIRST_PAINT), slice);
    editManifest(tree, (manifest) => {
      const subset = subsetsOf(manifest).find((entry) => entry.file === FIRST_PAINT);

      subset.bytes = slice.length;
      subset.sha256 = createHash('sha256').update(slice).digest('hex');
      subset.codepoints = 190;
    });

    const missing = violationsOf(tree, 'coverage/profile');

    expect(
      missing.map((violation) => violation.message),
      'the file that shipped for months passed this gate, which would make the gate ' +
        'worth nothing at all',
    ).toEqual(
      expect.arrayContaining([
        expect.stringContaining('the digits 0-9'),
        expect.stringContaining('the lowercase ASCII letters a-z'),
        expect.stringContaining('the ASCII punctuation source code is written in'),
      ]),
    );
  });

  it('a unicode-range wider than the file it names', () => {
    const tree = plantTree();

    // The whole Greek block, over a file that carries the eight letters Montserrat
    // borrowed for mathematics. A browser reading this stops looking for the other 120
    // and paints the fallback, which is the original defect wearing the split's clothes.
    editUnicodeRange(tree, 'montserrat-variable-greek.woff2', 'U+0370-03FF');

    const refused = violationsOf(tree, 'css/unicode-range');

    expect(
      refused.map((violation) => violation.message),
      'a rule could claim any range it liked over any file, and the browser would ' +
        'believe it — the failure mode the split introduced',
    ).toEqual(
      expect.arrayContaining([expect.stringContaining('codepoints this file does not carry')]),
    );
  });

  it('a unicode-range narrower than the file it names', () => {
    const tree = plantTree();

    editUnicodeRange(tree, 'overpass-variable-latin.woff2', 'U+0041');

    expect(
      violationsOf(tree, 'css/unicode-range').map((violation) => violation.message),
      'the reader would download 38 KB of Overpass to draw one capital A, and every ' +
        'other Latin character would fall back',
    ).toEqual(
      expect.arrayContaining([
        expect.stringContaining('codepoints the unicode-range does not claim'),
      ]),
    );
  });

  it('a @font-face served with no unicode-range at all', () => {
    const tree = plantTree();

    editUnicodeRange(tree, 'montserrat-variable-cyrillic.woff2', null);

    expect(
      violationsOf(tree, 'css/missing-unicode-range').length,
      'every reader would download the Cyrillic of a heading face to render "Save", ' +
        'which is the entire cost the split removed',
    ).toBe(1);
  });

  it('a split that has been undone, so every reader pays for every script', () => {
    const tree = plantTree();

    // What a re-merge looks like from here: every file becomes part of first paint. The
    // ceiling is the bundle the split replaced, so this is the exact regression it guards.
    editManifest(tree, (manifest) => {
      for (const subset of subsetsOf(manifest)) {
        subset.name = manifest.firstPaint.subset;
      }
    });

    expect(
      violationsOf(tree, 'budget/first-paint').map((violation) => violation.message),
      'the 45% regression this work was sent to repair could be reintroduced and ' +
        'nothing would say so',
    ).toEqual(expect.arrayContaining([expect.stringContaining('bytes before first paint')]));
  });

  it('a first-paint budget measuring a subset nothing belongs to', () => {
    const tree = plantTree();

    editManifest(tree, (manifest) => {
      manifest.firstPaint.subset = 'latin-renamed-in-a-refactor';
    });

    expect(
      violationsOf(tree, 'budget/no-first-paint-subset').length,
      'the budget would measure zero bytes against a real ceiling and could never fail',
    ).toBe(1);
  });

  it('a face that cannot draw a language the framework ships translations for', () => {
    const tree = plantTree();
    const path = join(tree, 'resources/lang/zz/core.php');

    mkdirSync(dirname(path), { recursive: true });
    // U+4E2D is in no bundled family, which is exactly the point: adding a locale the
    // bundle cannot render has to fail here rather than at a reader's screen.
    writeFileSync(path, "<?php\n\nreturn ['save' => '中'];\n", 'utf8');

    expect(
      violationsOf(tree, 'coverage/locale-unrenderable').length,
      'a translation nothing can draw would ship, and the reader who asked for it would ' +
        'get whatever their system happened to have',
    ).toBeGreaterThan(0);
  });

  it('a shipped locale whose script gap nobody recorded', () => {
    const tree = plantTree();

    // Greek is genuinely absent from both text families and no build can add it, so the
    // gap is recorded rather than fixed. Delete the record and the gate must say what it
    // has stopped saying: the framework ships translations it cannot draw.
    editManifest(tree, (manifest) => {
      manifest.knownGaps = [];
    });

    const refused = violationsOf(tree, 'coverage/locale-unrenderable');

    expect(
      refused.map((violation) => violation.subject),
      'the Greek gap could be deleted from the manifest and every locale would look fine',
    ).toEqual(expect.arrayContaining(['el in Montserrat', 'el in Overpass']));

    // Not only el: the language switcher of every locale carries the endonym Ελληνικά.
    expect(refused.length).toBeGreaterThan(2);
  });

  it('a recorded gap whose measured cost has drifted', () => {
    const tree = plantTree();

    editManifest(tree, (manifest) => {
      const greek = manifest.knownGaps[0];
      const el = greek.affectedLocales.find((row) => row.locale === 'el');

      el.missingCodepoints.Montserrat = 1;
    });

    expect(
      violationsOf(tree, 'coverage/gap-drift').map((violation) => violation.message),
      'a gap could be recorded as costing one codepoint while costing fifty-three, and ' +
        'the number in the repository would be decoration',
    ).toEqual(expect.arrayContaining([expect.stringContaining('is out of date')]));
  });

  it('a recorded gap that has stopped costing anything', () => {
    const tree = plantTree();

    editManifest(tree, (manifest) => {
      manifest.knownGaps[0].affectedLocales.push({
        locale: 'qq',
        missingCodepoints: { Montserrat: 4 },
      });
    });

    expect(
      violationsOf(tree, 'coverage/stale-gap').map((violation) => violation.message),
      'a gap could name locales the framework does not ship, and grow into a list ' +
        'nobody can check',
    ).toEqual(expect.arrayContaining([expect.stringContaining('no longer ships')]));
  });

  it('a subset that lost an OpenType feature the style sheets ask for', () => {
    const tree = plantTree();

    // What a rebuild that forgot to retain `tnum` would leave behind. The 33
    // `font-variant-numeric: tabular-nums` rules in this repository would then select
    // nothing: the text still renders, in proportional figures, and no column lines up.
    editManifest(tree, (manifest) => {
      const montserrat = manifest.families.find((family) => family.family === 'Montserrat');

      montserrat.openTypeFeatures = montserrat.openTypeFeatures.filter((tag) => tag !== 'tnum');

      for (const face of montserrat.faces) {
        for (const subset of face.subsets) {
          subset.features = subset.features.filter((tag) => tag !== 'tnum');
        }
      }
    });

    expect(
      violationsOf(tree, 'feature/unmet').map((violation) => violation.message),
      'tabular figures would silently stop working across the stat tiles, the invoice ' +
        'totals and the health status tables',
    ).toEqual(expect.arrayContaining([expect.stringContaining("ask for 'tnum'")]));
  });

  it('a manifest that describes features the font does not carry', () => {
    const tree = plantTree();

    editManifest(tree, (manifest) => {
      const subset = subsetsOf(manifest).find(
        (entry) => entry.file === 'overpass-variable-latin.woff2',
      );

      subset.features = [...subset.features, 'smcp'];
    });

    expect(
      violationsOf(tree, 'font/features').map((violation) => violation.message),
      'the manifest could promise any feature at all, and the only evidence for it ' +
        'would be that somebody typed it',
    ).toEqual(expect.arrayContaining([expect.stringContaining('declared and absent: smcp')]));
  });

  it('a style sheet asking for a feature the build never retained', () => {
    const tree = plantTree();
    const path = join(tree, 'resources/ui/css/components/stat.css');

    writeFileSync(
      path,
      `${readFileSync(path, 'utf8')}\n.small-caps-label {\n  font-variant-caps: small-caps;\n}\n`,
      'utf8',
    );

    expect(
      violationsOf(tree, 'css/unrequested-font-feature').map((violation) => violation.message),
      'a designer could add a feature request that reaches no font, and the page would ' +
        'render as though the rule were not there',
    ).toEqual(expect.arrayContaining([expect.stringContaining("asks for 'small-caps'")]));
  });

  it('a font replaced without anyone re-reading it', () => {
    const tree = plantTree();

    editManifest(tree, (manifest) => {
      subsetsOf(manifest)[0].sha256 = 'f'.repeat(64);
    });

    expect(violationsOf(tree, 'bundle/sha256').length).toBe(1);
  });

  it('a truncated font file', () => {
    const tree = plantTree();
    const path = join(tree, 'resources/ui/fonts/overpass-variable-latin.woff2');
    const whole = readFileSync(path);

    writeFileSync(path, whole.subarray(0, 4096));
    editManifest(tree, (manifest) => {
      const subset = subsetsOf(manifest).find(
        (entry) => entry.file === 'overpass-variable-latin.woff2',
      );

      subset.bytes = 4096;
      subset.sha256 = createHash('sha256').update(whole.subarray(0, 4096)).digest('hex');
    });

    expect(violationsOf(tree, 'font/unreadable').length).toBe(1);
  });

  it('a @font-face promising weights the font does not draw', () => {
    const tree = plantTree();
    const path = styleSheetPath(tree);
    const css = readFileSync(path, 'utf8');

    // JetBrains Mono varies over 100-800. Promising 900 makes the browser synthesise a
    // weight nobody drew, which is the quiet half of the same defect. Every subset of the
    // face carries the same axis, so every rule serving one is a separate promise.
    writeFileSync(path, css.replaceAll('font-weight: 100 800;', 'font-weight: 100 900;'), 'utf8');

    expect(violationsOf(tree, 'css/axis-range').length).toBeGreaterThan(0);
  });

  it('a font in the directory that no manifest entry describes', () => {
    const tree = plantTree();

    cpSync(SHIPPED_SLICE, join(tree, 'resources/ui/fonts/some-other-face.woff2'));

    expect(violationsOf(tree, 'bundle/undeclared-file').length).toBe(1);
  });

  it('a licence text that is not shipped with the font it licenses', () => {
    const tree = plantTree();

    rmSync(join(tree, 'resources/ui/fonts/OFL-Overpass.txt'));

    expect(violationsOf(tree, 'licence/missing').length).toBe(1);
  });

  it('a coverage requirement that has quietly become empty', () => {
    const tree = plantTree();

    editManifest(tree, (manifest) => {
      manifest.localeSources = ['resources/lang-that-moved'];
    });

    expect(violationsOf(tree, 'coverage/no-locale-corpus').length).toBe(1);
  });
});
