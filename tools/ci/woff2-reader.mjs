/**
 * Reads a bundled WOFF2 web font far enough to say what it can actually render.
 *
 * A font file is the one kind of asset nobody opens. `jetbrains-mono-variable.woff2`
 * sat in resources/ui/fonts/ at 15 KB holding 190 codepoints — no digits, no a-z, no
 * punctuation — and every code block, CLI transcript and monospace figure the framework
 * rendered fell back to Consolas. It looked exactly like a font, so nobody looked.
 *
 * Looking needs Brotli, which is why this half of the gate is JavaScript: PHP has no
 * brotli extension in this toolchain and `node:zlib` has had one since Node 12. Nothing
 * here is imported from npm — a gate that needs an install is a gate that gets skipped,
 * and skipping is how the broken slice survived.
 *
 * Only the tables the font contract is written against are decoded: `cmap` for coverage,
 * `fvar` for the axes a @font-face weight range makes a claim about, `maxp` for the
 * glyph count, and the feature lists of `GSUB` and `GPOS` for the OpenType features the
 * shipped style sheets ask for by name. The rest of the sfnt is left alone.
 *
 * The feature lists matter because subsetting drops what it is not told to keep.
 * `font-variant-numeric: tabular-nums` appears 33 times in this repository's style
 * sheets; the `tnum` feature it selects is not in fontTools' default retain list, so a
 * build that did not ask for it would produce a font in which those 33 rules select
 * nothing at all — text that renders, in the wrong figures, with nothing failing.
 */

import { brotliDecompressSync } from 'node:zlib';

/** WOFF2 signature: the four bytes every such file opens with. */
const SIGNATURE = 0x774f4632;

/** Divisor for the Fixed 16.16 values fvar stores axis bounds in. */
const FIXED_16_16 = 65536;

/**
 * The 63 table tags WOFF2 encodes as a 6-bit index, in the order the specification
 * numbers them. Index 63 means the tag follows as four literal bytes instead.
 *
 * @type {readonly string[]}
 */
const KNOWN_TAGS = [
  'cmap',
  'head',
  'hhea',
  'hmtx',
  'maxp',
  'name',
  'OS/2',
  'post',
  'cvt ',
  'fpgm',
  'glyf',
  'loca',
  'prep',
  'CFF ',
  'VORG',
  'EBDT',
  'EBLC',
  'gasp',
  'hdmx',
  'kern',
  'LTSH',
  'PCLT',
  'VDMX',
  'vhea',
  'vmtx',
  'BASE',
  'GDEF',
  'GPOS',
  'GSUB',
  'EBSC',
  'JSTF',
  'MATH',
  'CBDT',
  'CBLC',
  'COLR',
  'CPAL',
  'SVG ',
  'sbix',
  'acnt',
  'avar',
  'bdat',
  'bloc',
  'bsln',
  'cvar',
  'fdsc',
  'feat',
  'fmtx',
  'fvar',
  'gvar',
  'hsty',
  'just',
  'lcar',
  'mort',
  'morx',
  'opbd',
  'prop',
  'trak',
  'Zapf',
  'Silf',
  'Glat',
  'Gloc',
  'Feat',
  'Sill',
];

/**
 * Thrown when a file cannot be read as a WOFF2 at all.
 *
 * Kept distinct from a coverage failure on purpose: "this is not a font" and "this font
 * cannot render digits" are different findings, and a reader acts on them differently.
 */
export class Woff2FormatError extends Error {}

/**
 * A parsed WOFF2, reduced to what the font contract asks about.
 *
 * @typedef {object} Woff2Font
 * @property {string[]} tables table tags, in table-directory order
 * @property {number} numGlyphs glyph count, from maxp
 * @property {Set<number>} codepoints every codepoint the cmap maps to a real glyph
 * @property {Record<string, {min: number, default: number, max: number}>} axes variable
 *   axes from fvar, keyed by tag; empty for a static font
 * @property {Set<string>} features OpenType feature tags from the GSUB and GPOS feature
 *   lists; empty for a font with no layout tables
 */

/**
 * Parses a WOFF2 file.
 *
 * @param {Buffer} bytes the whole file
 * @returns {Woff2Font} what the font covers and how it varies
 * @throws {Woff2FormatError} when the header, table directory or compressed body is bad
 */
export function readWoff2(bytes) {
  if (bytes.length < 48) {
    throw new Woff2FormatError(`file is ${bytes.length} bytes; a WOFF2 header alone is 48`);
  }

  const signature = bytes.readUInt32BE(0);

  if (signature !== SIGNATURE) {
    throw new Woff2FormatError(`signature is 0x${signature.toString(16)}, not 'wOF2' (0x774f4632)`);
  }

  const declaredLength = bytes.readUInt32BE(8);

  if (declaredLength !== bytes.length) {
    throw new Woff2FormatError(
      `header declares ${declaredLength} bytes, the file is ${bytes.length} — truncated or padded`,
    );
  }

  const numTables = bytes.readUInt16BE(12);

  if (numTables === 0) {
    throw new Woff2FormatError('the table directory is empty');
  }

  const totalCompressedSize = bytes.readUInt32BE(20);
  const directory = readTableDirectory(bytes, numTables);

  if (directory.offset + totalCompressedSize > bytes.length) {
    throw new Woff2FormatError(
      `the compressed body runs past the end of the file: ${directory.offset} + ` +
        `${totalCompressedSize} > ${bytes.length}`,
    );
  }

  let sfnt;

  try {
    sfnt = brotliDecompressSync(
      bytes.subarray(directory.offset, directory.offset + totalCompressedSize),
    );
  } catch (cause) {
    throw new Woff2FormatError(`the compressed body is not valid Brotli: ${String(cause)}`);
  }

  const tables = locateTables(directory.entries, sfnt);

  return {
    tables: directory.entries.map((entry) => entry.tag),
    numGlyphs: readNumGlyphs(tables),
    codepoints: readCmap(tables),
    axes: readFvar(tables),
    features: readFeatures(tables),
  };
}

/**
 * @param {Buffer} bytes
 * @param {number} numTables
 * @returns {{entries: Array<{tag: string, length: number}>, offset: number}}
 */
function readTableDirectory(bytes, numTables) {
  const entries = [];
  let offset = 48;

  for (let index = 0; index < numTables; index++) {
    if (offset >= bytes.length) {
      throw new Woff2FormatError(`the table directory ends after ${index} of ${numTables} entries`);
    }

    const flags = bytes.readUInt8(offset);
    offset += 1;

    const tagIndex = flags & 0x3f;
    let tag;

    if (tagIndex === 0x3f) {
      tag = bytes.toString('latin1', offset, offset + 4);
      offset += 4;
    } else {
      tag = KNOWN_TAGS[tagIndex] ?? `?${tagIndex}`;
    }

    const original = readUIntBase128(bytes, offset);
    offset = original.offset;

    // The transformed length is present only when a transform is applied, and glyf and
    // loca invert the convention: for them version 0 is the transform and version 3 is
    // the null transform, while every other table transforms at a non-zero version.
    const transformVersion = (flags >> 6) & 0x03;
    const transformed =
      tag === 'glyf' || tag === 'loca' ? transformVersion === 0 : transformVersion !== 0;

    let length = original.value;

    if (transformed) {
      const transform = readUIntBase128(bytes, offset);
      offset = transform.offset;
      length = transform.value;
    }

    entries.push({ tag, length });
  }

  return { entries, offset };
}

/**
 * Tables sit back to back in the decompressed stream, in table-directory order.
 *
 * @param {Array<{tag: string, length: number}>} entries
 * @param {Buffer} sfnt
 * @returns {Map<string, Buffer>}
 */
function locateTables(entries, sfnt) {
  const tables = new Map();
  let offset = 0;

  for (const entry of entries) {
    if (offset + entry.length > sfnt.length) {
      throw new Woff2FormatError(
        `table '${entry.tag}' claims ${entry.length} bytes at ${offset}, but the decompressed ` +
          `font is only ${sfnt.length} bytes`,
      );
    }

    tables.set(entry.tag, sfnt.subarray(offset, offset + entry.length));
    offset += entry.length;
  }

  return tables;
}

/**
 * A base-128 variable-length integer: the high bit is set on every byte but the last.
 *
 * @param {Buffer} bytes
 * @param {number} start
 * @returns {{value: number, offset: number}}
 */
function readUIntBase128(bytes, start) {
  let value = 0;
  let offset = start;

  for (let index = 0; index < 5; index++) {
    if (offset >= bytes.length) {
      throw new Woff2FormatError(`the UIntBase128 at ${start} runs past the end of the file`);
    }

    const byte = bytes.readUInt8(offset);
    offset += 1;

    // A leading zero byte would let one number be written two ways, which the
    // specification forbids so that a directory has exactly one encoding.
    if (index === 0 && byte === 0x80) {
      throw new Woff2FormatError(`the UIntBase128 at ${start} has a leading zero byte`);
    }

    value = value * 128 + (byte & 0x7f);

    if ((byte & 0x80) === 0) {
      return { value, offset };
    }
  }

  throw new Woff2FormatError(`the UIntBase128 at ${start} is longer than five bytes`);
}

/**
 * @param {Map<string, Buffer>} tables
 * @returns {number}
 */
function readNumGlyphs(tables) {
  const maxp = tables.get('maxp');

  if (maxp === undefined || maxp.length < 6) {
    throw new Woff2FormatError('the maxp table is missing or too short to hold a glyph count');
  }

  return maxp.readUInt16BE(4);
}

/**
 * Every codepoint the font maps to a real glyph.
 *
 * Both Unicode subtable formats a modern web font uses are read — format 4 for the BMP
 * and format 12 above it — and the union is returned, which is what a browser resolves
 * against. Mappings to glyph 0 are excluded: .notdef is the absence of a glyph written
 * down, and counting it as coverage is how a font that renders nothing passes a coverage
 * gate.
 *
 * @param {Map<string, Buffer>} tables
 * @returns {Set<number>}
 */
function readCmap(tables) {
  const cmap = tables.get('cmap');

  if (cmap === undefined || cmap.length < 4) {
    throw new Woff2FormatError('the cmap table is missing or too short');
  }

  const codepoints = new Set();
  const numSubtables = cmap.readUInt16BE(2);

  for (let index = 0; index < numSubtables; index++) {
    const record = 4 + index * 8;

    if (record + 8 > cmap.length) {
      throw new Woff2FormatError(`cmap encoding record ${index} runs past the table`);
    }

    const subtable = cmap.readUInt32BE(record + 4);

    if (subtable + 2 > cmap.length) {
      throw new Woff2FormatError(`cmap subtable ${index} points outside the table`);
    }

    const format = cmap.readUInt16BE(subtable);

    if (format === 4) {
      readCmapFormat4(cmap, subtable, codepoints);
    } else if (format === 12) {
      readCmapFormat12(cmap, subtable, codepoints);
    }
  }

  if (codepoints.size === 0) {
    throw new Woff2FormatError('the cmap maps no codepoints at all');
  }

  return codepoints;
}

/**
 * @param {Buffer} cmap
 * @param {number} start
 * @param {Set<number>} into
 */
function readCmapFormat4(cmap, start, into) {
  const segCount = cmap.readUInt16BE(start + 6) / 2;
  const endCodes = start + 14;
  const startCodes = endCodes + segCount * 2 + 2;
  const idDeltas = startCodes + segCount * 2;
  const idRangeOffsets = idDeltas + segCount * 2;

  if (idRangeOffsets + segCount * 2 > cmap.length) {
    throw new Woff2FormatError('a cmap format 4 subtable runs past the table');
  }

  for (let segment = 0; segment < segCount; segment++) {
    const end = cmap.readUInt16BE(endCodes + segment * 2);
    const begin = cmap.readUInt16BE(startCodes + segment * 2);

    // 0xFFFF is the required terminating segment, not a character anyone types.
    if (begin === 0xffff) {
      continue;
    }

    const delta = cmap.readInt16BE(idDeltas + segment * 2);
    const rangeOffsetAt = idRangeOffsets + segment * 2;
    const rangeOffset = cmap.readUInt16BE(rangeOffsetAt);

    for (let codepoint = begin; codepoint <= end && codepoint !== 0xffff; codepoint++) {
      let glyph;

      if (rangeOffset === 0) {
        glyph = (codepoint + delta) & 0xffff;
      } else {
        const glyphAt = rangeOffsetAt + rangeOffset + (codepoint - begin) * 2;

        if (glyphAt + 2 > cmap.length) {
          throw new Woff2FormatError('a cmap format 4 glyph index runs past the table');
        }

        const raw = cmap.readUInt16BE(glyphAt);
        glyph = raw === 0 ? 0 : (raw + delta) & 0xffff;
      }

      if (glyph !== 0) {
        into.add(codepoint);
      }
    }
  }
}

/**
 * @param {Buffer} cmap
 * @param {number} start
 * @param {Set<number>} into
 */
function readCmapFormat12(cmap, start, into) {
  const groups = cmap.readUInt32BE(start + 12);

  for (let group = 0; group < groups; group++) {
    const at = start + 16 + group * 12;

    if (at + 12 > cmap.length) {
      throw new Woff2FormatError(`cmap format 12 group ${group} runs past the table`);
    }

    const begin = cmap.readUInt32BE(at);
    const end = cmap.readUInt32BE(at + 4);
    const startGlyph = cmap.readUInt32BE(at + 8);

    for (let codepoint = begin; codepoint <= end; codepoint++) {
      if (startGlyph + (codepoint - begin) !== 0) {
        into.add(codepoint);
      }
    }
  }
}

/**
 * The variable axes, which are what a @font-face weight range is a claim about.
 *
 * @param {Map<string, Buffer>} tables
 * @returns {Record<string, {min: number, default: number, max: number}>}
 */
function readFvar(tables) {
  const fvar = tables.get('fvar');

  if (fvar === undefined) {
    return {};
  }

  if (fvar.length < 16) {
    throw new Woff2FormatError('the fvar table is too short to hold its own header');
  }

  const axesOffset = fvar.readUInt16BE(4);
  const axisCount = fvar.readUInt16BE(8);
  const axisSize = fvar.readUInt16BE(10);
  /** @type {Record<string, {min: number, default: number, max: number}>} */
  const axes = {};

  for (let index = 0; index < axisCount; index++) {
    const at = axesOffset + index * axisSize;

    if (at + 16 > fvar.length) {
      throw new Woff2FormatError(`fvar axis ${index} runs past the table`);
    }

    axes[fvar.toString('latin1', at, at + 4)] = {
      min: fvar.readInt32BE(at + 4) / FIXED_16_16,
      default: fvar.readInt32BE(at + 8) / FIXED_16_16,
      max: fvar.readInt32BE(at + 12) / FIXED_16_16,
    };
  }

  return axes;
}

/**
 * The OpenType feature tags the font offers, across both layout tables.
 *
 * A GSUB or GPOS header is a version followed by three offsets, and the second of them
 * points at the feature list: a count, then that many records of a four-byte tag and an
 * offset. Only the tags are needed — whether a feature exists at all is the claim a
 * `font-variant-*` declaration makes — so the lookups behind them are not followed.
 *
 * Version 1.1 appends a fourth offset after the three; it does not move the feature list,
 * so both versions are read the same way.
 *
 * @param {Map<string, Buffer>} tables
 * @returns {Set<string>}
 */
function readFeatures(tables) {
  const tags = new Set();

  for (const name of ['GSUB', 'GPOS']) {
    const table = tables.get(name);

    if (table === undefined) {
      continue;
    }

    if (table.length < 10) {
      throw new Woff2FormatError(`the ${name} table is too short to hold its own header`);
    }

    const featureList = table.readUInt16BE(6);

    if (featureList + 2 > table.length) {
      throw new Woff2FormatError(`the ${name} feature list points outside the table`);
    }

    const count = table.readUInt16BE(featureList);

    for (let index = 0; index < count; index++) {
      const record = featureList + 2 + index * 6;

      if (record + 6 > table.length) {
        throw new Woff2FormatError(`${name} feature record ${index} runs past the table`);
      }

      tags.add(table.toString('latin1', record, record + 4));
    }
  }

  return tags;
}
