/**
 * Refuses a bundled web font that cannot render what it is bundled to render, and a
 * `unicode-range` that claims coverage its file does not carry.
 *
 * WHY THIS EXISTS
 *
 * `resources/ui/fonts/jetbrains-mono-variable.woff2` was, until this gate was written, a
 * latin-ext subset slice of JetBrains Mono: 15 KB, 190 codepoints, cmap U+000D-U+2113,
 * with no digits, no a-z and no punctuation. It is the file a browser downloads for
 * `--font-mono`, so every code block, every CLI transcript and every monospace figure the
 * framework has ever rendered fell through to Consolas or ui-monospace. The bundled code
 * face had never rendered code.
 *
 * Auditing the other four faces found the same failure twice more, quieter: both Overpass
 * files were the latin slice, 232 codepoints, so the 164 Latin Extended-A and Cyrillic
 * characters that the shipped cs, pl, hu, lt, lv, mt, ro, sk, sl, hr, et and bg
 * translations are written in fell back too — in the body face, on every page.
 *
 * WHY IT WAS EXTENDED
 *
 * The repair for that shipped every face's complete upstream coverage as one file per
 * style, and the bundle grew 45%: 535,208 bytes to 776,208, paid by every reader on every
 * first paint including the English one who will never see a Cyrillic character. Each face
 * is now split into one file per script, served by an `@font-face` carrying the
 * `unicode-range` it covers.
 *
 * That split invents a new way to lie. A rule may name a range wider than its file
 * carries, and the browser will believe it: it stops looking, finds no glyph, and draws
 * the fallback — the original defect in a new shape, and this time with the evidence
 * spread across two files. So `css/unicode-range` below compares the declared range of
 * every rule against the cmap of the file it names, exactly, in both directions.
 *
 * WHAT IT CHECKS
 *
 * Each rule below is one claim someone could otherwise make without evidence:
 *
 *   bundle/*    the files on disk are exactly the files declared, byte for byte
 *   font/*      the file parses as a WOFF2 and holds what the manifest says it holds
 *   css/*       tokens.css and the manifest agree about family, style, weight and range
 *   coverage/*  each family covers its role and the text the framework itself ships
 *   feature/*   the OpenType features the style sheets ask for survived subsetting
 *   licence/*   the OFL text is redistributed alongside the fonts, as the OFL requires
 *   budget/*    a Latin reader does not pay more than the bundle the split replaced
 *
 * The coverage requirement is derived, not typed: it is the set of codepoints that
 * actually occur in resources/lang/. Add a locale the bundled families cannot render and
 * this fails on the next run, which is the only way that stays true.
 *
 * Run it directly for a report:  node tools/ci/verify-bundled-fonts.mjs
 * It is also driven by tools/ci/verify-bundled-fonts.test.mjs under `pnpm test`.
 */

import { createHash } from 'node:crypto';
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

import { Woff2FormatError, readWoff2 } from './woff2-reader.mjs';

/** Where the manifest lives, relative to the repository root. */
const MANIFEST = 'resources/ui/fonts/fonts.manifest.json';

/**
 * What each role has to be able to draw.
 *
 * The requirement is on the family, not on any one file: since the split, no single
 * subset holds both the digits and the Latin-1 letters, and demanding that of the Greek
 * subset would fail a correct bundle. What must hold is that the union of a family's
 * subsets, at a given style, draws all of it — which is exactly what a browser resolves
 * against once the `unicode-range` rules are in place.
 *
 * The mono profile is spelled out in four named groups rather than one range, because
 * "U+0020-U+007E" in a failure message is not a sentence anyone acts on and "the code
 * face has no digits" is.
 *
 * @type {Record<string, Array<{what: string, codepoints: number[]}>>}
 */
const PROFILES = {
  mono: [
    { what: 'the digits 0-9', codepoints: range(0x30, 0x39) },
    { what: 'the lowercase ASCII letters a-z', codepoints: range(0x61, 0x7a) },
    { what: 'the uppercase ASCII letters A-Z', codepoints: range(0x41, 0x5a) },
    { what: 'the ASCII punctuation source code is written in', codepoints: asciiPunctuation() },
    { what: 'the Latin-1 letters user data carries', codepoints: latin1Printable() },
  ],
  heading: [
    { what: 'the printable ASCII range', codepoints: range(0x20, 0x7e) },
    { what: 'the Latin-1 letters user data carries', codepoints: latin1Printable() },
  ],
  body: [
    { what: 'the printable ASCII range', codepoints: range(0x20, 0x7e) },
    { what: 'the Latin-1 letters user data carries', codepoints: latin1Printable() },
  ],
};

/**
 * One thing wrong with the bundle.
 *
 * @typedef {object} Violation
 * @property {string} rule stable identifier, e.g. `coverage/profile`
 * @property {string} subject the file or family the finding is about
 * @property {string} message what is wrong, in the words a reviewer would use
 */

/**
 * One bundled file, as the report prints it.
 *
 * @typedef {object} AuditedSubset
 * @property {string} file
 * @property {string} family
 * @property {string} role
 * @property {string} style
 * @property {string} subset
 * @property {number} bytes
 * @property {number} codepoints
 */

/**
 * What a shipped locale costs the reader who asks for it.
 *
 * @typedef {object} LocaleReport
 * @property {string} locale
 * @property {number} codepoints how many distinct characters the translations use
 * @property {Record<string, number>} unrenderable per family, how many of them cannot
 *   be drawn; absent families draw all of it
 */

/**
 * Audits a checkout's bundled fonts.
 *
 * @param {string} root repository root to audit; a planted tree in the negative tests
 * @returns {{violations: Violation[], subsets: AuditedSubset[], totalBytes: number,
 *   firstPaintBytes: number, firstPaintCeiling: number, locales: LocaleReport[]}}
 */
export function auditBundledFonts(root) {
  /** @type {Violation[]} */
  const violations = [];
  const manifestPath = join(root, MANIFEST);

  if (!existsSync(manifestPath)) {
    return {
      violations: [
        {
          rule: 'bundle/no-manifest',
          subject: MANIFEST,
          message: 'the font manifest is missing, so nothing states what the bundle must cover',
        },
      ],
      subsets: [],
      totalBytes: 0,
      firstPaintBytes: 0,
      firstPaintCeiling: 0,
      locales: [],
    };
  }

  const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
  const fontDirectory = join(root, manifest.fontDirectory);
  const corpora = localeCorpora(root, manifest.localeSources);
  const required = union([...corpora.values()]);
  const declarations = readFontFaces(join(root, manifest.styleSheet));
  const firstPaintSubset = manifest.firstPaint?.subset ?? 'latin';
  const firstPaintCeiling = manifest.firstPaint?.ceilingBytes ?? 0;

  if (required.size === 0) {
    violations.push({
      rule: 'coverage/no-locale-corpus',
      subject: String(manifest.localeSources),
      message:
        'no locale strings were found, so the coverage requirement would be empty and every ' +
        'face would pass it — which is the failure mode this gate exists to refuse',
    });
  }

  checkStyleSheetFeatureRequests(root, manifest, violations);

  const declared = new Set();
  /** @type {AuditedSubset[]} */
  const subsets = [];
  /** @type {Map<string, Set<number>>} coverage of a family at a style, keyed 'family|style' */
  const coverage = new Map();
  let totalBytes = 0;
  let firstPaintBytes = 0;

  for (const family of manifest.families) {
    checkLicence(fontDirectory, family, violations);
    checkFeatureAvailability(family, manifest, violations);

    for (const face of family.faces) {
      const drawn = new Set();

      for (const subset of face.subsets) {
        declared.add(subset.file);

        const path = join(fontDirectory, subset.file);

        if (!existsSync(path)) {
          violations.push({
            rule: 'bundle/missing-file',
            subject: subset.file,
            message: `${family.family} declares this subset but no such file is bundled`,
          });

          continue;
        }

        const bytes = readFileSync(path);
        totalBytes += bytes.length;

        if (subset.name === firstPaintSubset) {
          firstPaintBytes += bytes.length;
        }

        subsets.push({
          file: subset.file,
          family: family.family,
          role: family.role,
          style: face.style,
          subset: subset.name,
          bytes: bytes.length,
          codepoints: subset.codepoints,
        });

        checkBytes(subset, bytes, violations);

        let font;

        try {
          font = readWoff2(bytes);
        } catch (cause) {
          violations.push({
            rule: 'font/unreadable',
            subject: subset.file,
            message:
              cause instanceof Woff2FormatError
                ? `not a usable WOFF2: ${cause.message}`
                : `could not be read: ${String(cause)}`,
          });

          continue;
        }

        for (const codepoint of font.codepoints) {
          drawn.add(codepoint);
        }

        checkDeclaredShape(face, subset, font, violations);
        checkFeatures(subset, font, violations);
        checkStyleSheet(family, face, subset, font, declarations, violations);
      }

      coverage.set(`${family.family}|${face.style}`, drawn);
      checkProfile(family, face, drawn, violations);
    }
  }

  const locales = measureLocales(manifest, corpora, coverage);

  checkKnownGaps(manifest, corpora, coverage, violations);
  checkLocaleCoverage(manifest, corpora, coverage, violations);
  checkNothingUndeclared(fontDirectory, declared, violations);
  checkEveryFaceIsReferenced(declarations, declared, manifest, violations);
  checkFirstPaintBudget(manifest, firstPaintBytes, totalBytes, firstPaintCeiling, violations);

  return { violations, subsets, totalBytes, firstPaintBytes, firstPaintCeiling, locales };
}

/**
 * The bytes on disk must be the bytes that were verified.
 *
 * This is the join between the two halves of the gate: this file proves what a given set
 * of bytes covers, and `BundledFontAssetGateTest` proves the same bytes are what ships.
 * Neither half can bless a broken face alone.
 *
 * @param {{file: string, bytes: number, sha256: string}} subset
 * @param {Buffer} bytes
 * @param {Violation[]} violations
 */
function checkBytes(subset, bytes, violations) {
  if (bytes.length !== subset.bytes) {
    violations.push({
      rule: 'bundle/bytes',
      subject: subset.file,
      message: `the manifest records ${subset.bytes} bytes, the file is ${bytes.length}`,
    });
  }

  const digest = createHash('sha256').update(bytes).digest('hex');

  if (digest !== subset.sha256) {
    violations.push({
      rule: 'bundle/sha256',
      subject: subset.file,
      message:
        `the file hashes to ${digest}, the manifest records ${subset.sha256}. Either the font ` +
        'was replaced without re-verifying it, or the manifest was edited to match a font ' +
        'nobody read',
    });
  }
}

/**
 * @param {{style: string, axes: Record<string, {min: number, default: number, max: number}>}} face
 * @param {{file: string, codepoints: number}} subset
 * @param {import('./woff2-reader.mjs').Woff2Font} font
 * @param {Violation[]} violations
 */
function checkDeclaredShape(face, subset, font, violations) {
  if (font.codepoints.size !== subset.codepoints) {
    violations.push({
      rule: 'font/codepoint-count',
      subject: subset.file,
      message:
        `the manifest claims ${subset.codepoints} codepoints, the cmap maps ` +
        `${font.codepoints.size}`,
    });
  }

  // Every subset of a face is cut from the same variable source, so they all vary over
  // the same axes. One that does not was built from something else.
  for (const [tag, declared] of Object.entries(face.axes)) {
    const actual = font.axes[tag];

    if (actual === undefined) {
      violations.push({
        rule: 'font/axes',
        subject: subset.file,
        message: `the manifest declares a '${tag}' axis this subset does not have`,
      });

      continue;
    }

    if (actual.min !== declared.min || actual.max !== declared.max) {
      violations.push({
        rule: 'font/axes',
        subject: subset.file,
        message:
          `the manifest declares '${tag}' ${declared.min}-${declared.max}, the fvar table ` +
          `says ${actual.min}-${actual.max}`,
      });
    }
  }
}

/**
 * The OpenType features a subset carries are a claim like any other.
 *
 * `font-variant-numeric: tabular-nums` appears 33 times in the shipped style sheets. The
 * feature it selects, `tnum`, is not in fontTools' default retain list, so a subsetting
 * build that did not ask for it would drop the feature and leave every one of those rules
 * selecting nothing — text that renders, in the wrong figures, with nothing failing.
 *
 * @param {{file: string, features: string[]}} subset
 * @param {import('./woff2-reader.mjs').Woff2Font} font
 * @param {Violation[]} violations
 */
function checkFeatures(subset, font, violations) {
  const declared = [...(subset.features ?? [])].sort();
  const actual = [...font.features].sort();

  if (declared.join(' ') !== actual.join(' ')) {
    const missing = declared.filter((tag) => !font.features.has(tag));
    const extra = actual.filter((tag) => !declared.includes(tag));

    violations.push({
      rule: 'font/features',
      subject: subset.file,
      message:
        'the OpenType features in the file are not the ones the manifest records' +
        (missing.length > 0 ? `; declared and absent: ${missing.join(' ')}` : '') +
        (extra.length > 0 ? `; present and undeclared: ${extra.join(' ')}` : ''),
    });
  }
}

/**
 * Every feature the style sheets ask for has to reach a font that answers it.
 *
 * @param {{family: string, openTypeFeatures: string[],
 *   cssFeaturesAbsentFromThisFamily: Array<{feature: string, reason: string}>}} family
 * @param {{openTypeFeaturesRequestedByStyleSheets: string[]}} manifest
 * @param {Violation[]} violations
 */
function checkFeatureAvailability(family, manifest, violations) {
  const carried = new Set(family.openTypeFeatures ?? []);
  const excused = new Map(
    (family.cssFeaturesAbsentFromThisFamily ?? []).map((entry) => [entry.feature, entry.reason]),
  );

  for (const tag of manifest.openTypeFeaturesRequestedByStyleSheets ?? []) {
    if (carried.has(tag) || excused.has(tag)) {
      continue;
    }

    violations.push({
      rule: 'feature/unmet',
      subject: family.family,
      message:
        `the style sheets ask for '${tag}' and no subset of this family carries it, so those ` +
        'rules select nothing and the text renders as though they were not written',
    });
  }

  for (const [tag, reason] of excused) {
    if (!carried.has(tag)) {
      if (typeof reason !== 'string' || reason.trim() === '') {
        violations.push({
          rule: 'feature/unexplained-exemption',
          subject: family.family,
          message: `'${tag}' is declared absent from this family with no reason recorded`,
        });
      }

      continue;
    }

    violations.push({
      rule: 'feature/stale-exemption',
      subject: family.family,
      message:
        `'${tag}' is declared absent from this family, and its subsets carry it. Remove the ` +
        'declaration — an exemption nobody needs is a hole waiting for the next font',
    });
  }
}

/**
 * A style sheet may not ask for a feature nothing has recorded.
 *
 * The manifest carries the CSS-keyword-to-OpenType-tag table from the CSS Fonts
 * specification so that it exists once rather than here and in the build script. A
 * keyword this gate cannot map through it is a keyword the build never saw, which means
 * the feature it selects was never retained.
 *
 * @param {string} root
 * @param {{cssFeatureKeywords: Record<string, string>, styleSheetSources: string[],
 *   openTypeFeaturesRequestedByStyleSheets: string[]}} manifest
 * @param {Violation[]} violations
 */
function checkStyleSheetFeatureRequests(root, manifest, violations) {
  const table = manifest.cssFeatureKeywords ?? {};
  const requested = new Set(manifest.openTypeFeaturesRequestedByStyleSheets ?? []);
  const known = new Set(Object.values(table));

  for (const source of manifest.styleSheetSources ?? []) {
    for (const directory of expand(join(root, source))) {
      for (const file of filesUnder(directory, '.css')) {
        const css = readFileSync(file, 'utf8');

        for (const match of css.matchAll(/font-variant[a-z-]*:\s*([^;}]+)/g)) {
          for (const keyword of (match[1] ?? '').trim().split(/[\s,]+/)) {
            const tag = table[keyword];

            if (tag !== undefined && !requested.has(tag)) {
              violations.push({
                rule: 'css/unrequested-font-feature',
                subject: file,
                message:
                  `asks for '${keyword}' (${tag}) and the manifest does not list that feature ` +
                  'as requested, so the build never retained it',
              });
            }
          }
        }

        for (const match of css.matchAll(/font-feature-settings:\s*([^;}]+)/g)) {
          for (const tag of (match[1] ?? '').matchAll(/['"]([A-Za-z0-9]{4})['"]/g)) {
            const value = tag[1] ?? '';

            if (!requested.has(value) && !known.has(value)) {
              violations.push({
                rule: 'css/unmapped-font-feature',
                subject: file,
                message:
                  `sets the '${value}' feature directly, and nothing in the manifest records ` +
                  'that the build was asked to keep it',
              });
            }
          }
        }
      }
    }
  }
}

/**
 * A @font-face saying `font-weight: 100 800` over a file whose axis does not span that is
 * the same defect as a missing glyph, one level quieter: the browser synthesises the
 * weights it was promised and the design renders in something nobody chose.
 *
 * `unicode-range` is the same defect again, and the one the split introduced. A rule
 * claiming a range its file does not carry stops the browser looking any further: it
 * finds no glyph in the face it was told to use, and paints the fallback. So the declared
 * range is compared against the cmap in both directions — a range narrower than the file
 * wastes the bytes it downloaded, a range wider than the file silently loses characters.
 *
 * @param {{family: string}} family
 * @param {{style: string, weightRange: [number, number] | null}} face
 * @param {{file: string, name: string}} subset
 * @param {import('./woff2-reader.mjs').Woff2Font} font
 * @param {FontFaceDeclaration[]} declarations
 * @param {Violation[]} violations
 */
function checkStyleSheet(family, face, subset, font, declarations, violations) {
  const declaration = declarations.find((entry) => entry.file === subset.file);

  if (declaration === undefined) {
    violations.push({
      rule: 'css/unreferenced-face',
      subject: subset.file,
      message: `bundled and declared in the manifest, but no @font-face in the style sheet uses it`,
    });

    return;
  }

  if (declaration.family !== family.family) {
    violations.push({
      rule: 'css/family-name',
      subject: subset.file,
      message:
        `the style sheet serves this file as '${declaration.family}', the manifest calls it ` +
        `'${family.family}'`,
    });
  }

  if (declaration.style !== face.style) {
    violations.push({
      rule: 'css/font-style',
      subject: subset.file,
      message: `the style sheet declares font-style: ${declaration.style}, the manifest says ${face.style}`,
    });
  }

  checkUnicodeRange(subset, font, declaration, violations);

  const weight = declaration.weight;
  const axis = font.axes['wght'];

  if (weight === null) {
    return;
  }

  if (axis === undefined) {
    violations.push({
      rule: 'css/axis-range',
      subject: subset.file,
      message:
        `the style sheet declares font-weight: ${weight.from} ${weight.to}, but the font has ` +
        'no weight axis at all',
    });

    return;
  }

  if (weight.from < axis.min || weight.to > axis.max) {
    violations.push({
      rule: 'css/axis-range',
      subject: subset.file,
      message:
        `the style sheet promises font-weight ${weight.from}-${weight.to}, the font's weight ` +
        `axis only spans ${axis.min}-${axis.max}. The weights outside it are synthesised, not drawn`,
    });
  }
}

/**
 * @param {{file: string}} subset
 * @param {import('./woff2-reader.mjs').Woff2Font} font
 * @param {FontFaceDeclaration} declaration
 * @param {Violation[]} violations
 */
function checkUnicodeRange(subset, font, declaration, violations) {
  if (declaration.unicodeRange === undefined) {
    violations.push({
      rule: 'css/missing-unicode-range',
      subject: subset.file,
      message:
        'the style sheet serves this subset without a unicode-range, so every reader ' +
        'downloads it whatever their page says — which is the whole cost the split removed',
    });

    return;
  }

  const claimed = codepointsOf(declaration.unicodeRange);
  const overclaimed = [...claimed].filter((codepoint) => !font.codepoints.has(codepoint));
  const unclaimed = [...font.codepoints].filter((codepoint) => !claimed.has(codepoint));

  if (overclaimed.length > 0) {
    violations.push({
      rule: 'css/unicode-range',
      subject: subset.file,
      message:
        `the unicode-range claims ${overclaimed.length} codepoints this file does not carry, ` +
        `so a browser stops looking and draws the fallback for them: ${format(overclaimed)}`,
    });
  }

  if (unclaimed.length > 0) {
    violations.push({
      rule: 'css/unicode-range',
      subject: subset.file,
      message:
        `this file carries ${unclaimed.length} codepoints the unicode-range does not claim, ` +
        `so they are downloaded and never used: ${format(unclaimed)}`,
    });
  }
}

/**
 * @param {{family: string, role: string}} family
 * @param {{style: string}} face
 * @param {Set<number>} drawn everything the family draws at this style
 * @param {Violation[]} violations
 */
function checkProfile(family, face, drawn, violations) {
  const profile = PROFILES[family.role];

  if (profile === undefined) {
    violations.push({
      rule: 'coverage/unknown-role',
      subject: family.family,
      message: `role '${family.role}' has no coverage profile, so nothing would be required of it`,
    });

    return;
  }

  for (const group of profile) {
    const missing = group.codepoints.filter((codepoint) => !drawn.has(codepoint));

    if (missing.length > 0) {
      violations.push({
        rule: 'coverage/profile',
        subject: `${family.family} ${face.style}`,
        message:
          `${family.family} is the ${family.role} face and is missing ${missing.length} of ` +
          `${group.codepoints.length} characters in ${group.what}: ${format(missing)}`,
      });
    }
  }
}

/**
 * What each shipped locale costs, measured rather than asserted.
 *
 * This is the number the framework had no way of stating before. `el` renders headings
 * and body text in whatever the reader's system happens to supply, and so — because every
 * locale's language switcher carries the endonym 'Ελληνικά' — does one word on every page
 * of the other twenty-four.
 *
 * @param {{families: Array<{family: string, role: string, faces: Array<{style: string}>}>}} manifest
 * @param {Map<string, Set<number>>} corpora
 * @param {Map<string, Set<number>>} coverage
 * @returns {LocaleReport[]}
 */
function measureLocales(manifest, corpora, coverage) {
  const reports = [];

  for (const locale of [...corpora.keys()].sort()) {
    const corpus = corpora.get(locale) ?? new Set();
    /** @type {Record<string, number>} */
    const unrenderable = {};

    for (const family of manifest.families) {
      // Only the families that set prose. The mono face draws code and figures, never a
      // translated sentence, and `checkLocaleCoverage` excuses it for the same reason —
      // a report that measured more than the rule enforces would invite the reader to
      // act on a number nothing defends.
      if (family.role === 'mono') {
        continue;
      }

      const missing = missingFrom(family, corpus, coverage);

      if (missing.length > 0) {
        unrenderable[family.family] = missing.length;
      }
    }

    reports.push({ locale, codepoints: corpus.size, unrenderable });
  }

  return reports;
}

/**
 * Everything a family cannot draw at any of its styles.
 *
 * @param {{family: string, faces: Array<{style: string}>}} family
 * @param {Set<number>} corpus
 * @param {Map<string, Set<number>>} coverage
 * @returns {number[]}
 */
function missingFrom(family, corpus, coverage) {
  const missing = new Set();

  for (const face of family.faces) {
    const drawn = coverage.get(`${family.family}|${face.style}`) ?? new Set();

    for (const codepoint of corpus) {
      if (!drawn.has(codepoint)) {
        missing.add(codepoint);
      }
    }
  }

  return [...missing].sort((a, b) => a - b);
}

/**
 * Every character the framework's own translations are written in has to be drawable by
 * the family that will be asked to draw it, unless a recorded gap says why not.
 *
 * The mono family is exempt from this by role, not by declaration: it sets code and
 * figures, never a translated sentence.
 *
 * @param {{families: Array<{family: string, role: string, faces: Array<{style: string}>}>,
 *   knownGaps: Array<{families: string[], affectedLocales: Array<{locale: string}>}>}} manifest
 * @param {Map<string, Set<number>>} corpora
 * @param {Map<string, Set<number>>} coverage
 * @param {Violation[]} violations
 */
function checkLocaleCoverage(manifest, corpora, coverage, violations) {
  for (const family of manifest.families) {
    if (family.role === 'mono') {
      continue;
    }

    for (const [locale, corpus] of corpora) {
      const missing = missingFrom(family, corpus, coverage);

      if (missing.length === 0) {
        continue;
      }

      const recorded = (manifest.knownGaps ?? []).some(
        (gap) =>
          gap.families.includes(family.family) &&
          gap.affectedLocales.some((row) => row.locale === locale),
      );

      if (recorded) {
        continue;
      }

      violations.push({
        rule: 'coverage/locale-unrenderable',
        subject: `${locale} in ${family.family}`,
        message:
          `the framework ships ${locale} translations and ${family.family} cannot draw ` +
          `${missing.length} of the characters they are written in, so they render in a ` +
          `fallback font: ${format(missing)}. Either bundle a family that covers them or ` +
          'record the gap in fonts.manifest.json — shipping a locale nothing can render is ' +
          'the one option this refuses',
      });
    }
  }
}

/**
 * A recorded gap is a measurement with a date on it, not a permission slip.
 *
 * Greek is genuinely absent from Montserrat and Overpass and no build can add it, so the
 * gap is recorded rather than fixed. What is refused is the gap drifting: a locale that
 * has stopped being affected, a locale whose cost has changed, or a gap that costs nobody
 * anything and is only excusing the next font to be dropped in under the same name.
 *
 * @param {{knownGaps: Array<{script: string, from: string, to: string, families: string[],
 *   reason: string, trackedBy: string, affectedLocales: Array<{locale: string,
 *   missingCodepoints: Record<string, number>}>}>, families: Array<{family: string,
 *   faces: Array<{style: string}>}>}} manifest
 * @param {Map<string, Set<number>>} corpora
 * @param {Map<string, Set<number>>} coverage
 * @param {Violation[]} violations
 */
function checkKnownGaps(manifest, corpora, coverage, violations) {
  for (const gap of manifest.knownGaps ?? []) {
    if (typeof gap.reason !== 'string' || gap.reason.trim() === '') {
      violations.push({
        rule: 'coverage/unexplained-gap',
        subject: gap.script ?? '(unnamed gap)',
        message: 'a gap is recorded with no reason, which excuses it to nobody',
      });
    }

    const from = codepointOf(gap.from);
    const to = codepointOf(gap.to);

    if (gap.affectedLocales.length === 0) {
      violations.push({
        rule: 'coverage/stale-gap',
        subject: gap.script,
        message:
          'this gap is recorded as costing no shipped locale anything, so it excuses ' +
          'nothing. Remove it',
      });
    }

    for (const row of gap.affectedLocales) {
      const corpus = corpora.get(row.locale);

      if (corpus === undefined) {
        violations.push({
          rule: 'coverage/stale-gap',
          subject: `${gap.script} / ${row.locale}`,
          message: `recorded as affecting the ${row.locale} locale, which the framework no longer ships`,
        });

        continue;
      }

      for (const name of gap.families) {
        const family = manifest.families.find((entry) => entry.family === name);

        if (family === undefined) {
          violations.push({
            rule: 'coverage/stale-gap',
            subject: `${gap.script} / ${name}`,
            message: `recorded against '${name}', which is not a bundled family`,
          });

          continue;
        }

        const missing = missingFrom(family, corpus, coverage);
        const recorded = row.missingCodepoints[name];

        if (missing.length === 0 && recorded !== undefined) {
          violations.push({
            rule: 'coverage/stale-gap',
            subject: `${gap.script} / ${row.locale} / ${name}`,
            message:
              `recorded as costing ${recorded} codepoints, and ${name} now draws every ` +
              'character the locale uses. Remove the entry',
          });

          continue;
        }

        if (missing.length > 0 && recorded !== missing.length) {
          violations.push({
            rule: 'coverage/gap-drift',
            subject: `${gap.script} / ${row.locale} / ${name}`,
            message:
              `recorded as costing ${recorded ?? 0} codepoints, and ${name} in fact cannot ` +
              `draw ${missing.length}. The recorded cost of a gap is a measurement, and this ` +
              'one is out of date',
          });
        }

        const outside = missing.filter((codepoint) => codepoint < from || codepoint > to);

        if (outside.length > 0) {
          violations.push({
            rule: 'coverage/locale-unrenderable',
            subject: `${row.locale} in ${name}`,
            message:
              `${outside.length} characters the ${row.locale} translations use lie outside the ` +
              `recorded ${gap.script} gap and are drawn by nothing: ${format(outside)}`,
          });
        }
      }
    }
  }
}

/**
 * The split has to be worth what it cost to build.
 *
 * The ceiling is not a number somebody liked: it is the size of the bundle this split
 * replaced, when every reader downloaded every script of every family. A Latin reader may
 * not pay more than that, or the split has bought nothing and should be undone.
 *
 * @param {{firstPaint: {subset: string, ceilingSource: string}}} manifest
 * @param {number} firstPaintBytes
 * @param {number} totalBytes
 * @param {number} ceiling
 * @param {Violation[]} violations
 */
function checkFirstPaintBudget(manifest, firstPaintBytes, totalBytes, ceiling, violations) {
  if (ceiling <= 0) {
    violations.push({
      rule: 'budget/no-ceiling',
      subject: MANIFEST,
      message:
        'no first-paint ceiling is recorded, so the bundle could grow back to whatever it ' +
        'was and nothing would notice',
    });

    return;
  }

  if (firstPaintBytes === 0 && totalBytes > 0) {
    violations.push({
      rule: 'budget/no-first-paint-subset',
      subject: manifest.firstPaint.subset,
      message:
        `no bundled file belongs to the '${manifest.firstPaint.subset}' subset, so the ` +
        'first-paint figure the budget is measured against is zero and the budget cannot fail',
    });

    return;
  }

  if (firstPaintBytes > ceiling) {
    violations.push({
      rule: 'budget/first-paint',
      subject: `the '${manifest.firstPaint.subset}' subsets`,
      message:
        `a Latin reader downloads ${firstPaintBytes} bytes before first paint, over the ` +
        `${ceiling}-byte ceiling. That ceiling is ${manifest.firstPaint.ceilingSource}`,
    });
  }
}

/**
 * The OFL requires the licence to travel with the fonts. Shipping the binaries without it
 * is the redistribution defect equivalent of the coverage one: nothing renders wrong, and
 * the framework is out of compliance with the licence it names in its own style sheet.
 *
 * @param {string} fontDirectory
 * @param {{family: string, license: {identifier: string, file: string, sha256: string}}} family
 * @param {Violation[]} violations
 */
function checkLicence(fontDirectory, family, violations) {
  const path = join(fontDirectory, family.license.file);

  if (!existsSync(path)) {
    violations.push({
      rule: 'licence/missing',
      subject: family.family,
      message:
        `${family.license.file} is not bundled. The OFL requires its text to be distributed ` +
        'with the font, and this repository redistributes the font',
    });

    return;
  }

  const text = readFileSync(path, 'utf8');
  const digest = createHash('sha256').update(readFileSync(path)).digest('hex');

  if (digest !== family.license.sha256) {
    violations.push({
      rule: 'licence/sha256',
      subject: family.family,
      message: `${family.license.file} hashes to ${digest}, the manifest records ${family.license.sha256}`,
    });
  }

  if (
    !text.includes('SIL OPEN FONT LICENSE Version 1.1') ||
    !text.includes('PERMISSION & CONDITIONS')
  ) {
    violations.push({
      rule: 'licence/text',
      subject: family.family,
      message: `${family.license.file} does not read like the SIL Open Font License 1.1`,
    });
  }
}

/**
 * @param {string} fontDirectory
 * @param {Set<string>} declared
 * @param {Violation[]} violations
 */
function checkNothingUndeclared(fontDirectory, declared, violations) {
  for (const entry of readdirSync(fontDirectory)) {
    if (entry.endsWith('.woff2') && !declared.has(entry)) {
      violations.push({
        rule: 'bundle/undeclared-file',
        subject: entry,
        message:
          'shipped in the font directory but absent from the manifest, so nothing states ' +
          'what it must cover and nothing has read it',
      });
    }
  }
}

/**
 * @param {FontFaceDeclaration[]} declarations
 * @param {Set<string>} declared
 * @param {{styleSheet: string}} manifest
 * @param {Violation[]} violations
 */
function checkEveryFaceIsReferenced(declarations, declared, manifest, violations) {
  for (const declaration of declarations) {
    if (!declared.has(declaration.file)) {
      violations.push({
        rule: 'css/unbundled-src',
        subject: declaration.file,
        message: `${manifest.styleSheet} serves this file, and it is not in the manifest`,
      });
    }
  }
}

/**
 * One @font-face rule, reduced to the claims it makes.
 *
 * @typedef {object} FontFaceDeclaration
 * @property {string} family
 * @property {string} file basename of the woff2 it points at
 * @property {string} style
 * @property {{from: number, to: number} | null} weight
 * @property {Array<{from: number, to: number}> | undefined} unicodeRange
 */

/**
 * Reads the @font-face rules out of a style sheet.
 *
 * @param {string} path
 * @returns {FontFaceDeclaration[]}
 */
export function readFontFaces(path) {
  if (!existsSync(path)) {
    return [];
  }

  const css = readFileSync(path, 'utf8');
  const declarations = [];

  for (const match of css.matchAll(/@font-face\s*\{([^}]*)\}/g)) {
    const block = match[1] ?? '';
    const family = /font-family:\s*['"]?([^;'"]+)['"]?\s*;/.exec(block);
    const source = /url\(\s*['"]?([^'")]+)['"]?\s*\)/.exec(block);

    if (family === null || source === null) {
      continue;
    }

    const weight = /font-weight:\s*(\d+)(?:\s+(\d+))?\s*;/.exec(block);
    const style = /font-style:\s*([a-z]+)\s*;/.exec(block);
    // Prettier breaks a long value onto the line after the colon, so the range may span
    // any number of lines before its semicolon.
    const unicodeRange = /unicode-range:\s*([^;]+);/.exec(block);

    declarations.push({
      family: (family[1] ?? '').trim(),
      file: (source[1] ?? '').split('/').pop() ?? '',
      style: style?.[1] ?? 'normal',
      weight:
        weight === null ? null : { from: Number(weight[1]), to: Number(weight[2] ?? weight[1]) },
      unicodeRange: unicodeRange === null ? undefined : parseUnicodeRange(unicodeRange[1] ?? ''),
    });
  }

  return declarations;
}

/**
 * Every codepoint the shipped translations are written in, per locale.
 *
 * @param {string} root
 * @param {string[]} sources directory patterns, with at most one `*` segment
 * @returns {Map<string, Set<number>>}
 */
export function localeCorpora(root, sources) {
  /** @type {Map<string, Set<number>>} */
  const corpora = new Map();

  for (const source of sources) {
    for (const directory of expand(join(root, source))) {
      for (const locale of readdirSync(directory)) {
        const path = join(directory, locale);

        if (!statSync(path).isDirectory()) {
          continue;
        }

        let corpus = corpora.get(locale);

        if (corpus === undefined) {
          corpus = new Set();
          corpora.set(locale, corpus);
        }

        for (const file of filesUnder(path, '.php')) {
          for (const character of readFileSync(file, 'utf8')) {
            const codepoint = character.codePointAt(0) ?? 0;

            // Tabs and newlines are layout, not glyphs; no font is asked to draw them.
            if (codepoint >= 0x20) {
              corpus.add(codepoint);
            }
          }
        }
      }
    }
  }

  return corpora;
}

/**
 * @param {Array<Set<number>>} sets
 * @returns {Set<number>}
 */
function union(sets) {
  const all = new Set();

  for (const set of sets) {
    for (const value of set) {
      all.add(value);
    }
  }

  return all;
}

/**
 * @param {string} pattern
 * @returns {string[]}
 */
function expand(pattern) {
  const star = pattern.indexOf('*');

  if (star === -1) {
    return existsSync(pattern) ? [pattern] : [];
  }

  const head = pattern.slice(0, star).replace(/[/\\]$/, '');
  const tail = pattern.slice(star + 1).replace(/^[/\\]/, '');

  if (!existsSync(head)) {
    return [];
  }

  return readdirSync(head)
    .map((entry) => join(head, entry, tail))
    .filter((path) => existsSync(path));
}

/**
 * @param {string} directory
 * @param {string} suffix
 * @returns {string[]}
 */
function filesUnder(directory, suffix) {
  const files = [];

  for (const entry of readdirSync(directory)) {
    const path = join(directory, entry);

    if (statSync(path).isDirectory()) {
      files.push(...filesUnder(path, suffix));
    } else if (entry.endsWith(suffix)) {
      files.push(path);
    }
  }

  return files;
}

/**
 * @param {string} value e.g. `U+0000-00FF, U+0131`
 * @returns {Array<{from: number, to: number}>}
 */
function parseUnicodeRange(value) {
  const ranges = [];

  for (const part of value.split(',')) {
    const match = /U\+([0-9A-Fa-f?]+)(?:-([0-9A-Fa-f]+))?/.exec(part.trim());

    if (match === null) {
      continue;
    }

    const from = (match[1] ?? '').replace(/\?/g, '0');
    const to = match[2] ?? (match[1] ?? '').replace(/\?/g, 'F');
    ranges.push({ from: parseInt(from, 16), to: parseInt(to, 16) });
  }

  return ranges;
}

/**
 * @param {Array<{from: number, to: number}>} ranges
 * @returns {Set<number>}
 */
function codepointsOf(ranges) {
  const codepoints = new Set();

  for (const span of ranges) {
    for (let codepoint = span.from; codepoint <= span.to; codepoint++) {
      codepoints.add(codepoint);
    }
  }

  return codepoints;
}

/**
 * @param {string} value e.g. `U+0370`
 * @returns {number}
 */
function codepointOf(value) {
  return parseInt(String(value).replace(/^U\+/, ''), 16);
}

/**
 * @param {number} from
 * @param {number} to
 * @returns {number[]}
 */
function range(from, to) {
  return Array.from({ length: to - from + 1 }, (_, index) => from + index);
}

/**
 * The 32 ASCII punctuation marks, which is most of what source code is made of.
 *
 * @returns {number[]}
 */
function asciiPunctuation() {
  return [...range(0x21, 0x2f), ...range(0x3a, 0x40), ...range(0x5b, 0x60), ...range(0x7b, 0x7e)];
}

/**
 * The printable Latin-1 supplement, less U+00AD.
 *
 * The soft hyphen is a formatting character rather than a mark on the page — a browser
 * draws a hyphen from its own hyphen glyph when a line breaks there — and neither
 * Montserrat nor most Google Fonts builds map it. Requiring it would fail complete,
 * correct fonts, which is how a gate earns its way into being switched off.
 *
 * @returns {number[]}
 */
function latin1Printable() {
  return range(0xa0, 0xff).filter((codepoint) => codepoint !== 0xad);
}

/**
 * @param {number[]} codepoints
 * @returns {string}
 */
function format(codepoints) {
  const shown = codepoints
    .slice(0, 12)
    .map((codepoint) => `U+${codepoint.toString(16).toUpperCase().padStart(4, '0')}`)
    .join(' ');

  return codepoints.length > 12 ? `${shown} … and ${codepoints.length - 12} more` : shown;
}

/**
 * Report, for a human running this directly.
 *
 * @param {string} root
 * @returns {number} process exit code
 */
function report(root) {
  const audit = auditBundledFonts(root);

  for (const subset of audit.subsets) {
    process.stdout.write(
      `  ${subset.file.padEnd(46)} ${String(subset.bytes).padStart(7)} bytes  ` +
        `${String(subset.codepoints).padStart(5)} codepoints  ${subset.family} ` +
        `${subset.style} (${subset.role})\n`,
    );
  }

  process.stdout.write(
    `\n  ${'first paint, Latin-only page'.padEnd(46)} ${String(audit.firstPaintBytes).padStart(7)} bytes\n` +
      `  ${'whole bundle'.padEnd(46)} ${String(audit.totalBytes).padStart(7)} bytes\n` +
      `  ${'ceiling (the bundle the split replaced)'.padEnd(46)} ${String(audit.firstPaintCeiling).padStart(7)} bytes\n\n`,
  );

  const unrenderable = audit.locales.filter(
    (locale) => Object.keys(locale.unrenderable).length > 0,
  );

  if (unrenderable.length > 0) {
    process.stdout.write(
      `  ${unrenderable.length} of ${audit.locales.length} shipped locales cannot be fully drawn ` +
        'by the bundled families:\n',
    );

    for (const locale of unrenderable) {
      const detail = Object.entries(locale.unrenderable)
        .map(([family, count]) => `${family} ${count}`)
        .join(', ');

      process.stdout.write(
        `    ${locale.locale.padEnd(6)} ${String(locale.codepoints).padStart(4)} codepoints used, ` +
          `unrenderable: ${detail}\n`,
      );
    }

    process.stdout.write('\n');
  }

  if (audit.violations.length === 0) {
    process.stdout.write(
      'Bundled fonts verified: every subset covers exactly the range it claims, every family\n' +
        'covers its role and the text it ships for, and every gap above is recorded and current.\n',
    );

    return 0;
  }

  for (const violation of audit.violations) {
    process.stderr.write(`  [${violation.rule}] ${violation.subject}: ${violation.message}\n`);
  }

  process.stderr.write(
    `\n${audit.violations.length} problem(s). A bundled face that cannot draw what it is bundled ` +
      'to draw ships as a silent fallback to whatever the reader happens to have.\n',
  );

  return 1;
}

if (process.argv[1] !== undefined && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  // An explicit root is what the negative tests audit a planted tree with; without one,
  // the checkout this file lives in.
  const root = process.argv[2] ?? resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');

  process.exit(report(resolve(root)));
}
