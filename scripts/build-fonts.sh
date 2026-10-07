#!/usr/bin/env bash
#
# Pulsar UI — rebuilds the bundled web fonts from pinned upstream sources.
#
# WHY THIS REPLACED scripts/download-fonts.sh
#
# The old script fetched "latest" release archives and wrote static per-weight files —
# montserrat-400.woff2, jetbrains-mono-700.woff2 — none of which are the files the
# framework actually bundles or that tokens.css actually names. It had not produced the
# contents of resources/ui/fonts/ for as long as those contents had existed, so the fonts
# in the tree came from somewhere nobody recorded.
#
# Somewhere turned out to be the Google Fonts CSS API, one `unicode-range` slice at a
# time. jetbrains-mono-variable.woff2 was the *latin-ext* slice: 190 codepoints, no
# digits, no a-z, no punctuation, shipped as the whole code face. Both Overpass files were
# the *latin* slice, so twelve of the shipped translations rendered in a fallback font.
#
# WHY IT WAS REWRITTEN AGAIN
#
# The first repair shipped every face's complete upstream coverage as one file per style.
# That fixed the glyphs and cost 45% more bytes — 535,208 to 776,208 — on every reader,
# including the English one who will never see a Cyrillic character. Downloading the
# Cyrillic, Greek and Vietnamese of a font to render "Save" is not a fix, it is the same
# indifference to the reader pointed the other way.
#
# So the faces are now split the way Google Fonts splits them: one file per script, each
# served by an `@font-face` carrying the `unicode-range` it actually covers, so a browser
# fetches only the subsets the page's text needs. Coverage is unchanged — the union of a
# face's subsets is asserted equal to the source cmap, codepoint for codepoint — and a
# Latin reader's first paint drops from 776,208 bytes to 187,004.
#
# WHAT IT PROMISES
#
# Byte-identical output, which the previous version of this file said it could not.
# That claim was wrong, and wrong in an interesting way: it blamed "a compressor's mood",
# when the cause was `TTFont.save()` restamping `head.modified` from the wall clock on
# every write. Passing `recalcTimestamp=False` makes three consecutive builds of the same
# source produce the same SHA-256, which was measured before this line was written.
#
# That is what makes `bash scripts/build-fonts.sh --check` meaningful: it rebuilds every
# face into a temporary directory and compares hashes against the committed tree, so the
# provenance recorded in fonts.manifest.json can be re-derived rather than believed.
#
# What is also asserted, on every run: the cmap of each subset equals the codepoints it
# was asked to carry, no more and no less; the union of a face's subsets equals its
# source's cmap; and every OpenType feature the shipped style sheets ask for survives
# subsetting in the family that has it.
#
# Usage:
#   bash scripts/build-fonts.sh            rebuild resources/ui/fonts/ and the generated
#                                          @font-face region of resources/ui/css/tokens.css
#   bash scripts/build-fonts.sh --check    rebuild into a temporary directory and fail if
#                                          any committed byte differs
#
# Requires: curl, python3 with fonttools and brotli (pip install fonttools brotli), node.

set -euo pipefail

cd "$(dirname "$0")/.."

MODE="write"

if [ $# -gt 0 ]; then
    case "$1" in
        --check) MODE="check" ;;
        *)
            echo "  ! unknown argument '$1'; usage: bash scripts/build-fonts.sh [--check]" >&2
            exit 2
            ;;
    esac
fi

FONT_DIR="resources/ui/fonts"
STYLE_SHEET="resources/ui/css/tokens.css"
TEMP_DIR=$(mktemp -d)
trap 'rm -rf "$TEMP_DIR"' EXIT

# family | google/fonts commit | upstream directory
FAMILIES=(
    "jetbrainsmono|6e4b84c976cadb3c49a40fd9a1c203e4f7fcf2da|ofl/jetbrainsmono"
    "montserrat|8b0a1d0f5983c89bc2b93f1b5fb55f9e252744b5|ofl/montserrat"
    "overpass|8b0a1d0f5983c89bc2b93f1b5fb55f9e252744b5|ofl/overpass"
)

# family | upstream file
SOURCES=(
    "jetbrainsmono|JetBrainsMono[wght].ttf"
    "montserrat|Montserrat[wght].ttf"
    "montserrat|Montserrat-Italic[wght].ttf"
    "overpass|Overpass[wght].ttf"
    "overpass|Overpass-Italic[wght].ttf"
)

echo "Pulsar UI — bundled font build (${MODE})"
echo "======================================"
echo ""

for tool in curl python3 node; do
    if ! command -v "$tool" >/dev/null 2>&1; then
        echo "  ! $tool is required and was not found" >&2
        exit 1
    fi
done

if ! python3 -c "import fontTools, brotli" >/dev/null 2>&1; then
    echo "  ! python3 needs fonttools and brotli: pip install fonttools brotli" >&2
    exit 1
fi

mkdir -p "$FONT_DIR" "$TEMP_DIR/upstream"

# ---------------------------------------------------------------------------
# Fetch the pinned sources. A commit-pinned raw URL is content-addressed, so the
# bytes cannot change under the pin — which is the property "latest" never had.
# ---------------------------------------------------------------------------
: >"$TEMP_DIR/pins.txt"

for entry in "${FAMILIES[@]}"; do
    IFS='|' read -r family commit path <<<"$entry"

    echo "$family|$commit|$path" >>"$TEMP_DIR/pins.txt"

    echo "[fetch] $family @ ${commit:0:12}"
    curl -sSfL -o "$TEMP_DIR/upstream/OFL-$family.txt" \
        "https://raw.githubusercontent.com/google/fonts/$commit/$path/OFL.txt"

    for source in "${SOURCES[@]}"; do
        [ "${source%%|*}" = "$family" ] || continue

        file="${source#*|}"
        # The upstream names carry the axis in brackets. A URL may not hold them
        # literally, and a Windows curl refuses to write a local path containing them,
        # so the download is encoded on the way out and de-bracketed on the way in.
        encoded=${file//\[/%5B}
        encoded=${encoded//\]/%5D}
        local_name=${file/\[wght\]/}

        echo "        $file"
        curl -sSfL -o "$TEMP_DIR/upstream/$local_name" \
            "https://raw.githubusercontent.com/google/fonts/$commit/$path/$encoded"
    done
done

echo ""

if [ "$MODE" = "check" ]; then
    OUTPUT_DIR="$TEMP_DIR/rebuilt"
    mkdir -p "$OUTPUT_DIR"
    echo "[build] rebuilding into a temporary directory to compare against the tree"
else
    OUTPUT_DIR="$FONT_DIR"
    echo "[build] subsetting, writing the manifest and regenerating the @font-face region"
fi

# ---------------------------------------------------------------------------
# The build itself. Everything it decides is derived from the sources it just
# fetched and the files already in the tree; nothing about coverage is typed here.
# ---------------------------------------------------------------------------
python3 - "$TEMP_DIR" "$OUTPUT_DIR" "$MODE" "$STYLE_SHEET" <<'PYTHON'
import hashlib
import json
import os
import re
import sys

from fontTools.subset import Options, Subsetter
from fontTools.ttLib import TTFont

temp, output_dir, mode, style_sheet = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4]
upstream = os.path.join(temp, "upstream")

# ---------------------------------------------------------------------------
# The subset table.
#
# Ordered, and the first entry whose ranges contain a codepoint claims it, so the
# ranges below may overlap and the result is still a partition: every codepoint of a
# source font lands in exactly one subset. That is the property that makes the split
# lossless, and it is asserted rather than assumed further down.
#
# The names and boundaries follow the ones Google Fonts serves, because those are the
# ones the web has agreed on and a reader's browser cache may already hold the shape of.
# Two deliberate departures, both to keep the partition honest:
#
#   * `latin-ext` is placed after `cyrillic`/`cyrillic-ext`, so U+2116 NUMERO SIGN and
#     U+20B4 HRYVNIA land with the script that uses them rather than in the currency
#     sweep of the Latin extension.
#   * the whole combining block U+0300-036F sits in `latin-ext` rather than being
#     duplicated into `latin` and `vietnamese`. Decomposed text is by definition not
#     plain Latin, and a codepoint carried twice is a codepoint downloaded twice.
#
# `symbols` is last and has no ranges: it takes whatever the font carries that no
# earlier entry claimed. It is what stops the table from silently dropping a codepoint
# when an upstream font grows a block nobody here thought about.
# ---------------------------------------------------------------------------
SUBSETS = [
    (
        "latin",
        "ASCII, Latin-1 and the punctuation, currency and typographic marks a Western "
        "European page is set in. This is the only subset a Latin-only reader downloads.",
        [
            (0x0000, 0x00FF), (0x0131, 0x0131), (0x0152, 0x0153), (0x02BB, 0x02BC),
            (0x02C6, 0x02C6), (0x02DA, 0x02DA), (0x02DC, 0x02DC), (0x2000, 0x206F),
            (0x2074, 0x2074), (0x20AC, 0x20AC), (0x2122, 0x2122), (0x2191, 0x2191),
            (0x2193, 0x2193), (0x2212, 0x2212), (0x2215, 0x2215), (0xFEFF, 0xFEFF),
            (0xFFFD, 0xFFFD),
        ],
    ),
    (
        "greek",
        "the Greek and Coptic block, which the el locale is written in.",
        [(0x0370, 0x03FF)],
    ),
    (
        "greek-ext",
        "polytonic Greek, for classical and liturgical text.",
        [(0x1F00, 0x1FFF)],
    ),
    (
        "cyrillic",
        "the Cyrillic the bg locale is written in, plus the numero sign that goes with it.",
        [(0x0400, 0x045F), (0x0490, 0x0491), (0x04B0, 0x04B1), (0x2116, 0x2116)],
    ),
    (
        "cyrillic-ext",
        "the Cyrillic beyond the Russian and Bulgarian alphabets, plus historic forms.",
        [
            (0x0460, 0x052F), (0x1C80, 0x1C8F), (0x20B4, 0x20B4), (0x2DE0, 0x2DFF),
            (0xA640, 0xA69F), (0xFE2E, 0xFE2F),
        ],
    ),
    (
        "vietnamese",
        "the precomposed Vietnamese block; no shipped locale needs it, and the glyphs "
        "exist upstream, so they are carried where nobody pays for them unread.",
        [(0x1EA0, 0x1EFF)],
    ),
    (
        "latin-ext",
        "Latin Extended-A through D and the combining marks: the cs, pl, hu, lt, lv, mt, "
        "ro, sk, sl, hr and et locales are written in this.",
        [
            (0x0100, 0x02FF), (0x0300, 0x036F), (0x1AB0, 0x1AFF), (0x1DC0, 0x1DFF),
            (0x1E00, 0x1E9F), (0x2070, 0x209F), (0x20A0, 0x20CF), (0x2100, 0x214F),
            (0x2C60, 0x2C7F), (0xA720, 0xA7FF), (0xAB30, 0xAB6F), (0xFB00, 0xFB4F),
        ],
    ),
    (
        "symbols",
        "everything else the upstream font carries — arrows, box drawing, mathematical "
        "operators — claimed by no earlier subset. It exists so the split cannot lose a "
        "codepoint the table did not anticipate.",
        [],
    ),
]

# The subset a reader who reads only Latin text pays for. Named rather than assumed,
# because the first-paint figure the gate holds the build to is the sum of these files.
FIRST_PAINT = "latin"

# The bundle as it stood before any of this: five whole-family files, 535,208 bytes,
# every one of which every reader downloaded. It is the ceiling the split has to stay
# under for a Latin reader, and it is a measurement rather than a target somebody liked —
# `git cat-file -s HEAD:resources/ui/fonts/*.woff2` at the commit this work began.
FIRST_PAINT_CEILING = 535208
FIRST_PAINT_CEILING_SOURCE = (
    "the total size of the five whole-family faces the framework bundled before they were "
    "split, measured with `git cat-file -s` at the commit this build script was written. "
    "The split may not cost a Latin reader more than the bundle it replaced."
)

# CSS keyword to OpenType feature tag, per CSS Fonts Module Level 4. This is the
# specification's table, not a fact about this repository, which is why it can live in
# one place and be read by the verifier out of the manifest instead of being retyped.
CSS_FEATURE_KEYWORDS = {
    "tabular-nums": "tnum",
    "proportional-nums": "pnum",
    "lining-nums": "lnum",
    "oldstyle-nums": "onum",
    "diagonal-fractions": "frac",
    "stacked-fractions": "afrc",
    "ordinal": "ordn",
    "slashed-zero": "zero",
    "common-ligatures": "liga",
    "discretionary-ligatures": "dlig",
    "historical-ligatures": "hlig",
    "contextual": "calt",
    "small-caps": "smcp",
    "all-small-caps": "c2sc",
}

# Where the style sheets that make those requests live. Scanned, not listed: a feature
# request added to an extension's CSS has to reach this build or it renders as nothing.
STYLE_SHEET_SOURCES = ["resources/ui/css", "extensions/*/resources/css"]

# Where the translations live. The coverage requirement is the set of codepoints these
# files actually contain, so adding a locale the fonts cannot draw fails the gate.
LOCALE_SOURCES = ["resources/lang", "extensions/*/resources/lang"]

# family key, display name, role, what it is used for, and its faces as
# (upstream file, bundled stem, css font-style).
FAMILIES = [
    (
        "jetbrainsmono",
        "JetBrains Mono",
        "mono",
        "code blocks, CLI transcripts, log output, monospace figures (--font-mono)",
        [("JetBrainsMono[wght].ttf", "jetbrains-mono-variable", "normal")],
    ),
    (
        "montserrat",
        "Montserrat",
        "heading",
        "headings and nav brands (--font-heading)",
        [
            ("Montserrat[wght].ttf", "montserrat-variable", "normal"),
            ("Montserrat-Italic[wght].ttf", "montserrat-italic-variable", "italic"),
        ],
    ),
    (
        "overpass",
        "Overpass",
        "body",
        "body text and UI chrome (--font-sans)",
        [
            ("Overpass[wght].ttf", "overpass-variable", "normal"),
            ("Overpass-Italic[wght].ttf", "overpass-italic-variable", "italic"),
        ],
    ),
]

# A script a family does not carry, with the reason and who is deciding about it. Only
# the prose lives here: which locales the gap actually costs, and how many codepoints it
# costs each of them, is measured below from the fonts and the translations.
GAPS = [
    {
        "script": "Greek and Coptic",
        "from": "U+0370",
        "to": "U+03FF",
        "families": ["Montserrat", "Overpass"],
        "reason": (
            "Neither Montserrat nor Overpass contains the Greek alphabet. Both carry a "
            "handful of Greek letters borrowed for mathematics — Montserrat 8, Overpass 4 "
            "— and neither carries the script. No build can close this; only a different "
            "family can."
        ),
        "trackedBy": (
            "the typography workstream, which may replace both text faces with families "
            "that cover Greek. Until it does, this gap is measured on every run rather "
            "than excused: the locales below and the codepoint counts beside them are "
            "recomputed by tools/ci/verify-bundled-fonts.mjs and must match exactly."
        ),
    },
]

# A CSS feature request an upstream family simply does not answer, with the reason it is
# not a defect. Which families these apply to is measured, not stated.
FEATURE_EXEMPTION_REASONS = {
    "JetBrains Mono": (
        "JetBrains Mono is monospaced: every figure already occupies one advance width, "
        "so tabular and proportional figure features would have nothing to change. The "
        "upstream font ships neither, and text set in it renders as the style sheet asks."
    ),
}

COMMITS = {}
for line in open(os.path.join(temp, "pins.txt"), encoding="utf-8"):
    key, commit, path = line.strip().split("|")
    COMMITS[key] = (commit, path)


def sha256_of(path):
    with open(path, "rb") as handle:
        return hashlib.sha256(handle.read()).hexdigest()


def codepoints_of(font):
    found = set()
    for table in font["cmap"].tables:
        found |= set(table.cmap.keys())
    return found


def features_of(font):
    tags = set()
    for tag in ("GSUB", "GPOS"):
        if tag in font:
            for record in font[tag].table.FeatureList.FeatureRecord:
                tags.add(record.FeatureTag)
    return tags


def expand(pattern):
    """Expands a repository-relative path with at most one `*` segment."""
    if "*" not in pattern:
        return [pattern] if os.path.isdir(pattern) else []

    head, tail = pattern.split("*", 1)
    head = head.rstrip("/")
    tail = tail.lstrip("/")

    if not os.path.isdir(head):
        return []

    found = []
    for entry in sorted(os.listdir(head)):
        candidate = os.path.join(head, entry, tail) if tail else os.path.join(head, entry)
        if os.path.isdir(candidate):
            found.append(candidate)
    return found


def files_under(directory, suffix):
    found = []
    for base, _, names in os.walk(directory):
        for name in sorted(names):
            if name.endswith(suffix):
                found.append(os.path.join(base, name))
    return sorted(found)


def compact_ranges(codepoints):
    """The shortest `unicode-range` value that names exactly these codepoints."""
    ordered = sorted(codepoints)
    spans = []
    first = previous = ordered[0]

    for codepoint in ordered[1:]:
        if codepoint == previous + 1:
            previous = codepoint
            continue
        spans.append((first, previous))
        first = previous = codepoint

    spans.append((first, previous))

    return ", ".join(
        "U+%04X" % low if low == high else "U+%04X-%04X" % (low, high) for low, high in spans
    )


# ---------------------------------------------------------------------------
# What the shipped style sheets ask the fonts for.
# ---------------------------------------------------------------------------
requested_features = set()
requesting_files = []

for pattern in STYLE_SHEET_SOURCES:
    for directory in expand(pattern):
        for path in files_under(directory, ".css"):
            css = open(path, encoding="utf-8").read()
            wanted = set()

            for value in re.findall(r"font-variant[a-z-]*:\s*([^;}]+)", css):
                for keyword in re.split(r"[\s,]+", value.strip()):
                    if keyword in CSS_FEATURE_KEYWORDS:
                        wanted.add(CSS_FEATURE_KEYWORDS[keyword])

            for value in re.findall(r"font-feature-settings:\s*([^;}]+)", css):
                for tag in re.findall(r"['\"]([A-Za-z0-9]{4})['\"]", value):
                    wanted.add(tag)

            if wanted:
                requested_features |= wanted
                requesting_files.append(
                    {"file": path.replace("\\", "/"), "features": sorted(wanted)}
                )

print("        style sheets ask for: %s" % (" ".join(sorted(requested_features)) or "(nothing)"))

# ---------------------------------------------------------------------------
# What the shipped translations are written in, per locale.
# ---------------------------------------------------------------------------
locale_corpora = {}

for pattern in LOCALE_SOURCES:
    for directory in expand(pattern):
        for locale in sorted(os.listdir(directory)):
            path = os.path.join(directory, locale)
            if not os.path.isdir(path):
                continue
            found = locale_corpora.setdefault(locale, set())
            for php in files_under(path, ".php"):
                for character in open(php, encoding="utf-8").read():
                    codepoint = ord(character)
                    # Tabs and newlines are layout, not glyphs; no font draws them.
                    if codepoint >= 0x20:
                        found.add(codepoint)

if not locale_corpora:
    raise SystemExit("no locale corpora were found, so nothing would constrain coverage")

print("        %d shipped locales: %s" % (len(locale_corpora), " ".join(sorted(locale_corpora))))
print("")

# ---------------------------------------------------------------------------
# Build.
# ---------------------------------------------------------------------------
families = []
family_coverage = {}
written = []

for key, name, role, used_for, faces in FAMILIES:
    commit, path = COMMITS[key]
    licence_source = os.path.join(upstream, "OFL-%s.txt" % key)
    licence_name = "OFL-%s.txt" % name.replace(" ", "")
    licence_target = os.path.join(output_dir, licence_name)

    with open(licence_source, "rb") as source, open(licence_target, "wb") as target:
        target.write(source.read())

    written.append(licence_name)

    built_faces = []
    version = None
    covered = set()
    family_features = set()
    upstream_features = set()

    for upstream_file, stem, style in faces:
        # The download strips the bracketed axis from the local name; the manifest keeps
        # the upstream one, because that is the name the pinned URL actually serves.
        source_path = os.path.join(upstream, upstream_file.replace("[wght]", ""))

        source_font = TTFont(source_path, lazy=True)
        source_codepoints = codepoints_of(source_font)
        source_features = features_of(TTFont(source_path, lazy=True))
        upstream_features |= source_features
        version = source_font["name"].getDebugName(5)
        axes = {
            axis.axisTag: {
                "min": int(axis.minValue),
                "default": int(axis.defaultValue),
                "max": int(axis.maxValue),
            }
            for axis in source_font["fvar"].axes
        }

        # Assign every codepoint to the first subset that claims it. `symbols` has no
        # ranges and takes the remainder, so the assignment is total by construction.
        assignment = {}
        for subset_name, _, ranges in SUBSETS:
            for codepoint in source_codepoints:
                if codepoint in assignment:
                    continue
                if any(low <= codepoint <= high for low, high in ranges):
                    assignment[codepoint] = subset_name

        for codepoint in source_codepoints:
            assignment.setdefault(codepoint, SUBSETS[-1][0])

        buckets = {}
        for codepoint, subset_name in assignment.items():
            buckets.setdefault(subset_name, set()).add(codepoint)

        # Only the features the style sheets actually ask for are added to the
        # subsetter's default retain list. Retaining everything inflates every subset by
        # a fifth for glyphs nothing in this repository ever selects.
        retained = sorted(requested_features & source_features)

        built_subsets = []
        union = set()

        for subset_name, _, _ in SUBSETS:
            wanted = buckets.get(subset_name)

            if not wanted:
                continue

            options = Options()
            options.layout_features = options.layout_features + retained

            # recalcTimestamp is what made this build unreproducible: TTFont.save() writes
            # head.modified from the wall clock unless told not to, so the same input
            # produced a different SHA-256 every run and the manifest could only ever be
            # believed. With it off, three consecutive builds hash identically.
            font = TTFont(source_path, recalcTimestamp=False)
            subsetter = Subsetter(options=options)
            subsetter.populate(unicodes=wanted)
            subsetter.subset(font)
            font.flavor = "woff2"

            file_name = "%s-%s.woff2" % (stem, subset_name)
            target = os.path.join(output_dir, file_name)
            font.save(target)
            written.append(file_name)

            # Read the file back rather than trusting what was handed to the writer. A
            # subset that claims a range it does not carry is the defect this split could
            # introduce, and the only way to know is to open the bytes that were written.
            readback = TTFont(target, lazy=True)
            carried = codepoints_of(readback)

            if carried != wanted:
                raise SystemExit(
                    "%s does not carry what it was built for: %d asked for, %d carried, "
                    "%d lost, %d unexpected"
                    % (
                        file_name,
                        len(wanted),
                        len(carried),
                        len(wanted - carried),
                        len(carried - wanted),
                    )
                )

            subset_features = features_of(TTFont(target, lazy=True))
            family_features |= subset_features
            union |= carried

            built_subsets.append(
                {
                    "name": subset_name,
                    "file": file_name,
                    "bytes": os.path.getsize(target),
                    "sha256": sha256_of(target),
                    "codepoints": len(carried),
                    "unicodeRange": compact_ranges(carried),
                    "features": sorted(subset_features),
                }
            )

            print(
                "        %-46s %7d bytes  %5d codepoints"
                % (file_name, built_subsets[-1]["bytes"], len(carried))
            )

        # The whole point of splitting: nothing may be lost on the way.
        if union != source_codepoints:
            raise SystemExit(
                "%s lost coverage in the split: %d codepoints in the source, %d across its "
                "subsets, %d missing, %d invented"
                % (
                    stem,
                    len(source_codepoints),
                    len(union),
                    len(source_codepoints - union),
                    len(union - source_codepoints),
                )
            )

        covered |= union
        weight = axes.get("wght")

        built_faces.append(
            {
                "style": style,
                "upstreamFile": upstream_file,
                "axes": axes,
                "weightRange": [weight["min"], weight["max"]] if weight else None,
                "subsets": built_subsets,
            }
        )

    # A feature the style sheets ask for that the upstream family does not have is not a
    # subsetting failure, and is only excusable with a reason. Which features those are is
    # measured here; the reason comes from the table above and must exist.
    absent = []
    for tag in sorted(requested_features - upstream_features):
        reason = FEATURE_EXEMPTION_REASONS.get(name)
        if reason is None:
            raise SystemExit(
                "%s has no '%s' feature and no recorded reason why that is acceptable. "
                "Add one to FEATURE_EXEMPTION_REASONS in scripts/build-fonts.sh, or the "
                "style sheet is asking for something this font silently will not do."
                % (name, tag)
            )
        absent.append({"feature": tag, "reason": reason})

    # A feature that survived subsetting in no subset at all would mean the style sheet
    # request reaches nothing, which is the tabular-figures shape of the original defect.
    for tag in sorted(requested_features & upstream_features):
        if tag not in family_features:
            raise SystemExit(
                "%s carries '%s' upstream and no subset of it kept the feature, so the "
                "style sheets asking for it would render unchanged text" % (name, tag)
            )

    family_coverage[name] = covered

    families.append(
        {
            "family": name,
            "role": role,
            "usedFor": used_for,
            "license": {
                "identifier": "OFL-1.1",
                "file": licence_name,
                "sha256": sha256_of(licence_target),
            },
            "upstream": {
                "repository": "https://github.com/google/fonts",
                "commit": commit,
                "path": "%s/%s" % (path, " + ".join(face[0] for face in faces)),
                "version": version,
                "sha256": sha256_of(os.path.join(upstream, faces[0][0].replace("[wght]", ""))),
                "conversion": (
                    "scripts/build-fonts.sh, fontTools Subsetter then TTFont.flavor='woff2' "
                    "with recalcTimestamp=False. Lossless and reproducible: the union of a "
                    "face's subsets equals its source's cmap, and a rebuild is byte-identical "
                    "(`bash scripts/build-fonts.sh --check`)."
                ),
            },
            "openTypeFeatures": sorted(family_features),
            "cssFeaturesAbsentFromThisFamily": absent,
            "faces": built_faces,
        }
    )

# ---------------------------------------------------------------------------
# What the gaps actually cost, per locale. Measured, then written down.
# ---------------------------------------------------------------------------
gaps = []

for gap in GAPS:
    low = int(gap["from"][2:], 16)
    high = int(gap["to"][2:], 16)
    affected = []

    for locale in sorted(locale_corpora):
        missing = {}
        for family_name in gap["families"]:
            absent_here = sorted(
                codepoint
                for codepoint in locale_corpora[locale]
                if codepoint not in family_coverage[family_name]
            )
            outside = [c for c in absent_here if not (low <= c <= high)]

            if outside:
                raise SystemExit(
                    "%s cannot draw %d codepoints of the %s locale that lie outside the "
                    "declared %s gap, starting at U+%04X. Add a gap that covers them or "
                    "the framework ships a translation nothing can render."
                    % (family_name, len(outside), locale, gap["script"], outside[0])
                )

            if absent_here:
                missing[family_name] = len(absent_here)

        if missing:
            affected.append({"locale": locale, "missingCodepoints": missing})

    if not affected:
        raise SystemExit(
            "the declared %s gap costs no shipped locale anything. Remove it — a gap "
            "nobody pays for excuses nothing and hides the next one." % gap["script"]
        )

    entry = dict(gap)
    entry["affectedLocales"] = affected
    gaps.append(entry)

    worst = max(affected, key=lambda row: max(row["missingCodepoints"].values()))
    print("")
    print(
        "        gap: %s absent from %s — %d of %d shipped locales affected, worst is "
        "'%s' at %d codepoints"
        % (
            gap["script"],
            " and ".join(gap["families"]),
            len(affected),
            len(locale_corpora),
            worst["locale"],
            max(worst["missingCodepoints"].values()),
        )
    )

# Any locale short a codepoint that no declared gap explains is a hard failure above;
# this asserts the converse, that nothing silently slipped past the loop.
for locale, corpus in sorted(locale_corpora.items()):
    for family in families:
        if family["role"] == "mono":
            continue
        unmet = [c for c in corpus if c not in family_coverage[family["family"]]]
        if unmet and not any(
            row["locale"] == locale
            for gap in gaps
            for row in gap["affectedLocales"]
            if family["family"] in row["missingCodepoints"]
        ):
            raise SystemExit(
                "the %s locale cannot be rendered by %s and no gap records it"
                % (locale, family["family"])
            )

# ---------------------------------------------------------------------------
# The manifest.
# ---------------------------------------------------------------------------
first_paint = sum(
    subset["bytes"]
    for family in families
    for face in family["faces"]
    for subset in face["subsets"]
    if subset["name"] == FIRST_PAINT
)
total = sum(
    subset["bytes"]
    for family in families
    for face in family["faces"]
    for subset in face["subsets"]
)

manifest = {
    "documentation": [
        "What is bundled in resources/ui/fonts/, where each file came from, and what it is required to cover.",
        "Written by scripts/build-fonts.sh. Two gates read it, and neither can bless a broken face alone:",
        "tools/ci/verify-bundled-fonts.mjs parses the WOFF2 bytes and proves the coverage, axes, features and unicode-ranges claimed here are real (pnpm test);",
        "tests/Unit/Integrity/BundledFontAssetGateTest.php proves the bytes on disk are the bytes verified here, and that tokens.css agrees (composer test).",
        "A latin-ext subset slice of JetBrains Mono once shipped as the whole code face: 190 codepoints, no digits, no a-z, no punctuation. Nothing was checking, so nothing failed.",
        "The repair for that shipped every face whole and cost every reader 45% more bytes. Each face is now split by script, and a browser fetches only the subsets a page's text needs.",
    ],
    "faceRoles": {
        "mono": "must render source code: digits, a-z, A-Z and every ASCII punctuation mark, plus the Latin-1 letters user data carries",
        "heading": "must render the Latin text the framework ships in resources/lang/",
        "body": "must render the Latin text the framework ships in resources/lang/",
    },
    "fontDirectory": "resources/ui/fonts",
    "styleSheet": style_sheet,
    "styleSheetRegion": {
        "begin": "/* pulsar:font-face:begin",
        "end": "/* pulsar:font-face:end */",
        "note": "Everything between these markers is written by scripts/build-fonts.sh. Editing it by hand makes the style sheet disagree with the files it names, which the gates refuse.",
    },
    "localeSources": LOCALE_SOURCES,
    "styleSheetSources": STYLE_SHEET_SOURCES,
    "subsetPolicy": [
        {"name": name, "why": why, "ranges": ["U+%04X-%04X" % r for r in ranges]}
        for name, why, ranges in SUBSETS
    ],
    "firstPaint": {
        "subset": FIRST_PAINT,
        "ceilingBytes": FIRST_PAINT_CEILING,
        "ceilingSource": FIRST_PAINT_CEILING_SOURCE,
    },
    "cssFeatureKeywords": CSS_FEATURE_KEYWORDS,
    "openTypeFeaturesRequestedByStyleSheets": sorted(requested_features),
    "styleSheetsRequestingFeatures": requesting_files,
    "knownGaps": gaps,
    "families": families,
}

manifest_name = "fonts.manifest.json"
manifest_path = os.path.join(output_dir, manifest_name)

with open(manifest_path, "w", encoding="utf-8", newline="\n") as handle:
    json.dump(manifest, handle, indent=2, ensure_ascii=False)
    handle.write("\n")

written.append(manifest_name)

print("")
print("        %-46s %7d bytes" % (manifest_name, os.path.getsize(manifest_path)))
print("")
print("        first paint (%s subsets only)   %7d bytes" % (FIRST_PAINT, first_paint))
print("        whole bundle                        %7d bytes" % total)
print(
    "        ceiling (the bundle this replaced)  %7d bytes  %s"
    % (FIRST_PAINT_CEILING, "OK" if first_paint <= FIRST_PAINT_CEILING else "EXCEEDED")
)

if first_paint > FIRST_PAINT_CEILING:
    raise SystemExit(
        "a Latin reader would download %d bytes, more than the %d-byte bundle this split "
        "replaced" % (first_paint, FIRST_PAINT_CEILING)
    )

# ---------------------------------------------------------------------------
# The @font-face region of the style sheet, generated from what was just built.
# ---------------------------------------------------------------------------
BEGIN = "/* pulsar:font-face:begin"
END = "/* pulsar:font-face:end */"

lines = [
    "%s — generated by scripts/build-fonts.sh; do not edit by hand." % BEGIN,
    " *",
    " * One @font-face per family, style and script subset. The unicode-range on each rule",
    " * is the exact set of codepoints the file it names carries, so a browser downloads a",
    " * subset only when the page's text needs it, and never downloads one that could not",
    " * have helped. A Latin-only page fetches %d bytes of the %d bundled." % (first_paint, total),
    " *",
    " * The ranges below are not descriptions, they are claims, and",
    " * tools/ci/verify-bundled-fonts.mjs reads every file back and refuses any rule whose",
    " * range is not exactly what its file covers. */",
    "",
]

for family in families:
    lines.append("/* %s — %s */" % (family["family"], family["usedFor"]))

    for face in family["faces"]:
        weight = face["weightRange"]

        for subset in face["subsets"]:
            lines.append("@font-face {")
            lines.append("  font-family: '%s';" % family["family"])
            lines.append("  src: url('../fonts/%s') format('woff2');" % subset["file"])
            if weight:
                lines.append("  font-weight: %d %d;" % (weight[0], weight[1]))
            lines.append("  font-style: %s;" % face["style"])
            lines.append("  font-display: swap;")
            lines.append("  unicode-range: %s;" % subset["unicodeRange"])
            lines.append("}")
            lines.append("")

lines.append(END)

region = "\n".join(lines)

style_sheet_path = style_sheet
existing = open(style_sheet_path, encoding="utf-8", newline="").read()
start = existing.find(BEGIN)
stop = existing.find(END)

if start == -1 or stop == -1:
    raise SystemExit(
        "%s has no '%s' … '%s' region for this script to write. Add the markers where the "
        "@font-face rules belong." % (style_sheet_path, BEGIN, END)
    )

updated = existing[:start] + region + existing[stop + len(END) :]

if mode == "check":
    expected_dir = os.path.join(output_dir, "stylesheet")
    os.makedirs(expected_dir, exist_ok=True)
    with open(os.path.join(expected_dir, "tokens.css"), "w", encoding="utf-8", newline="\n") as handle:
        handle.write(updated)
else:
    with open(style_sheet_path, "w", encoding="utf-8", newline="\n") as handle:
        handle.write(updated)

with open(os.path.join(temp, "written.txt"), "w", encoding="utf-8", newline="\n") as handle:
    handle.write("\n".join(sorted(set(written))) + "\n")
PYTHON

if [ "$MODE" = "check" ]; then
    echo ""
    echo "[check] comparing the rebuild against the committed tree"

    # The committed manifest and style sheet are Prettier-formatted, so the rebuild has to
    # be put through the same formatter with the same configuration before the two can be
    # compared. Without --config, Prettier would find no configuration beside a file in
    # the system temp directory and format it to its own defaults instead of the house
    # style, and every run would report a difference that is not one.
    if ! npx --no-install prettier --config .prettierrc.json --write \
        "$TEMP_DIR/rebuilt/fonts.manifest.json" \
        "$TEMP_DIR/rebuilt/stylesheet/tokens.css" >/dev/null 2>&1; then
        echo "  ! prettier is required to compare a rebuild against the tree" >&2
        exit 1
    fi

    status=0

    while IFS= read -r name; do
        [ -n "$name" ] || continue

        if [ ! -f "$FONT_DIR/$name" ]; then
            echo "  ! $name was rebuilt and is not in the tree" >&2
            status=1
            continue
        fi

        rebuilt=$(sha256sum "$TEMP_DIR/rebuilt/$name" | cut -d' ' -f1)
        committed=$(sha256sum "$FONT_DIR/$name" | cut -d' ' -f1)

        if [ "$rebuilt" != "$committed" ]; then
            echo "  ! $name differs: rebuilt $rebuilt, committed $committed" >&2
            status=1
        fi
    done <"$TEMP_DIR/written.txt"

    for existing in "$FONT_DIR"/*.woff2; do
        name=$(basename "$existing")

        if ! grep -qx "$name" "$TEMP_DIR/written.txt"; then
            echo "  ! $name is in the tree and the rebuild does not produce it" >&2
            status=1
        fi
    done

    if ! diff -q "$TEMP_DIR/rebuilt/stylesheet/tokens.css" "$STYLE_SHEET" >/dev/null 2>&1; then
        echo "  ! $STYLE_SHEET does not match what the build would generate" >&2
        status=1
    fi

    if [ "$status" -eq 0 ]; then
        echo "        every file rebuilds byte for byte, and the style sheet matches"
    fi

    exit "$status"
fi

# Both generated files are inside `pnpm format:check`, so they are formatted by the
# repository's own Prettier rather than by this script's idea of JSON and CSS.
# Reimplementing the house style here would put a second copy of .prettierrc.json in the
# tree, and the two would drift.
if npx --no-install prettier --write "$FONT_DIR/fonts.manifest.json" "$STYLE_SHEET" >/dev/null 2>&1; then
    echo "        formatted with the repository's Prettier"
else
    echo "  ! prettier was not available; run 'pnpm format:fix' before committing" >&2
fi

echo ""
echo "[verify] reading back what was written"
node tools/ci/verify-bundled-fonts.mjs
