#!/usr/bin/env bash
# Pulsar UI — Font Downloader
#
# Downloads and extracts self-hosted WOFF2 font files required by the design charter.
# Fonts: Montserrat, Overpass, JetBrains Mono (all SIL OFL 1.1).
#
# Usage: bash scripts/download-fonts.sh
#
# This script is idempotent — safe to run multiple times.

set -euo pipefail

FONT_DIR="resources/ui/fonts"
TEMP_DIR=$(mktemp -d)
trap 'rm -rf "$TEMP_DIR"' EXIT

mkdir -p "$FONT_DIR"

echo "Pulsar UI — Font Downloader"
echo "==========================="
echo ""

# ---------------------------------------------------------------------------
# Montserrat (GitHub releases)
# ---------------------------------------------------------------------------
MONTSERRAT_URL="https://github.com/JulietaUla/montserrat/releases/latest/download/Montserrat.zip"
echo "[1/3] Downloading Montserrat..."
curl -sSL -o "$TEMP_DIR/montserrat.zip" "$MONTSERRAT_URL"
unzip -qo "$TEMP_DIR/montserrat.zip" -d "$TEMP_DIR/montserrat"

# Find and copy the required WOFF2 files with correct naming
# Montserrat ships static TTF files; we need to convert or find WOFF2 variants.
# The GitHub release includes a webfonts/ directory with WOFF2 files in some releases.
# If WOFF2 files are not found, we fall back to the static TTF directory.

find_and_copy_montserrat() {
    local src_dir="$TEMP_DIR/montserrat"
    local weights=("Regular" "Medium" "SemiBold" "Bold")
    local weight_nums=("400" "500" "600" "700")

    # Try to find WOFF2 files first
    for i in "${!weights[@]}"; do
        local weight="${weights[$i]}"
        local num="${weight_nums[$i]}"
        local woff2_file
        woff2_file=$(find "$src_dir" -iname "Montserrat-${weight}.woff2" -type f 2>/dev/null | head -1)

        if [ -n "$woff2_file" ]; then
            cp "$woff2_file" "$FONT_DIR/montserrat-${num}.woff2"
            echo "  - montserrat-${num}.woff2 (from WOFF2)"
        else
            # Fall back to TTF — user will need to convert with woff2_compress
            local ttf_file
            ttf_file=$(find "$src_dir" -iname "Montserrat-${weight}.ttf" -path "*/static/*" -type f 2>/dev/null | head -1)
            if [ -z "$ttf_file" ]; then
                ttf_file=$(find "$src_dir" -iname "Montserrat-${weight}.ttf" -type f 2>/dev/null | head -1)
            fi
            if [ -n "$ttf_file" ]; then
                if command -v woff2_compress &>/dev/null; then
                    woff2_compress "$ttf_file"
                    local compressed="${ttf_file%.ttf}.woff2"
                    cp "$compressed" "$FONT_DIR/montserrat-${num}.woff2"
                    echo "  - montserrat-${num}.woff2 (converted from TTF)"
                else
                    cp "$ttf_file" "$FONT_DIR/montserrat-${num}.ttf"
                    echo "  - montserrat-${num}.ttf (TTF — run woff2_compress to convert)"
                fi
            else
                echo "  ! montserrat-${num} not found"
            fi
        fi
    done

    # Italic variants: 400-italic, 700-italic
    local italic_weights=("Italic" "BoldItalic")
    local italic_nums=("400" "700")
    for i in "${!italic_weights[@]}"; do
        local weight="${italic_weights[$i]}"
        local num="${italic_nums[$i]}"
        local woff2_file
        woff2_file=$(find "$src_dir" -iname "Montserrat-${weight}.woff2" -type f 2>/dev/null | head -1)

        if [ -n "$woff2_file" ]; then
            cp "$woff2_file" "$FONT_DIR/montserrat-${num}-italic.woff2"
            echo "  - montserrat-${num}-italic.woff2 (from WOFF2)"
        else
            local ttf_file
            ttf_file=$(find "$src_dir" -iname "Montserrat-${weight}.ttf" -path "*/static/*" -type f 2>/dev/null | head -1)
            if [ -z "$ttf_file" ]; then
                ttf_file=$(find "$src_dir" -iname "Montserrat-${weight}.ttf" -type f 2>/dev/null | head -1)
            fi
            if [ -n "$ttf_file" ]; then
                if command -v woff2_compress &>/dev/null; then
                    woff2_compress "$ttf_file"
                    cp "${ttf_file%.ttf}.woff2" "$FONT_DIR/montserrat-${num}-italic.woff2"
                    echo "  - montserrat-${num}-italic.woff2 (converted from TTF)"
                else
                    cp "$ttf_file" "$FONT_DIR/montserrat-${num}-italic.ttf"
                    echo "  - montserrat-${num}-italic.ttf (TTF — run woff2_compress to convert)"
                fi
            else
                echo "  ! montserrat-${num}-italic not found"
            fi
        fi
    done
}

find_and_copy_montserrat

# ---------------------------------------------------------------------------
# Overpass (GitHub releases)
# ---------------------------------------------------------------------------
OVERPASS_URL="https://github.com/RedHatOfficial/Overpass/releases/latest/download/overpass-webfonts.zip"
OVERPASS_ALT_URL="https://github.com/RedHatOfficial/Overpass/releases/latest/download/overpass-desktop.zip"
echo ""
echo "[2/3] Downloading Overpass..."
if curl -sSL -o "$TEMP_DIR/overpass.zip" "$OVERPASS_URL" 2>/dev/null; then
    echo "  Downloaded webfonts archive"
else
    curl -sSL -o "$TEMP_DIR/overpass.zip" "$OVERPASS_ALT_URL"
    echo "  Downloaded desktop archive (will need conversion)"
fi
unzip -qo "$TEMP_DIR/overpass.zip" -d "$TEMP_DIR/overpass"

find_and_copy_overpass() {
    local src_dir="$TEMP_DIR/overpass"
    local weights=("Light" "Regular" "Medium" "SemiBold" "Bold")
    local weight_nums=("300" "400" "500" "600" "700")

    for i in "${!weights[@]}"; do
        local weight="${weights[$i]}"
        local num="${weight_nums[$i]}"
        local woff2_file
        woff2_file=$(find "$src_dir" -iname "*overpass*${weight}*.woff2" -not -iname "*italic*" -not -iname "*mono*" -type f 2>/dev/null | head -1)

        if [ -z "$woff2_file" ]; then
            woff2_file=$(find "$src_dir" -iname "overpass-${weight,,}.woff2" -type f 2>/dev/null | head -1)
        fi

        if [ -n "$woff2_file" ]; then
            cp "$woff2_file" "$FONT_DIR/overpass-${num}.woff2"
            echo "  - overpass-${num}.woff2"
        else
            local ttf_file
            ttf_file=$(find "$src_dir" -iname "*overpass*${weight}*.ttf" -not -iname "*italic*" -not -iname "*mono*" -type f 2>/dev/null | head -1)
            if [ -n "$ttf_file" ]; then
                if command -v woff2_compress &>/dev/null; then
                    woff2_compress "$ttf_file"
                    cp "${ttf_file%.ttf}.woff2" "$FONT_DIR/overpass-${num}.woff2"
                    echo "  - overpass-${num}.woff2 (converted from TTF)"
                else
                    cp "$ttf_file" "$FONT_DIR/overpass-${num}.ttf"
                    echo "  - overpass-${num}.ttf (TTF — run woff2_compress to convert)"
                fi
            else
                echo "  ! overpass-${num} not found"
            fi
        fi
    done

    # Italic: 400-italic only
    local woff2_file
    woff2_file=$(find "$src_dir" -iname "*overpass*italic*.woff2" -not -iname "*bold*" -not -iname "*mono*" -type f 2>/dev/null | head -1)
    if [ -z "$woff2_file" ]; then
        woff2_file=$(find "$src_dir" -iname "overpass-regular-italic.woff2" -type f 2>/dev/null | head -1)
    fi

    if [ -n "$woff2_file" ]; then
        cp "$woff2_file" "$FONT_DIR/overpass-400-italic.woff2"
        echo "  - overpass-400-italic.woff2"
    else
        local ttf_file
        ttf_file=$(find "$src_dir" -iname "*overpass*italic*.ttf" -not -iname "*bold*" -not -iname "*mono*" -type f 2>/dev/null | head -1)
        if [ -n "$ttf_file" ]; then
            if command -v woff2_compress &>/dev/null; then
                woff2_compress "$ttf_file"
                cp "${ttf_file%.ttf}.woff2" "$FONT_DIR/overpass-400-italic.woff2"
                echo "  - overpass-400-italic.woff2 (converted from TTF)"
            else
                cp "$ttf_file" "$FONT_DIR/overpass-400-italic.ttf"
                echo "  - overpass-400-italic.ttf (TTF — run woff2_compress to convert)"
            fi
        else
            echo "  ! overpass-400-italic not found"
        fi
    fi
}

find_and_copy_overpass

# ---------------------------------------------------------------------------
# JetBrains Mono (GitHub releases)
# ---------------------------------------------------------------------------
JBMONO_URL="https://github.com/JetBrains/JetBrainsMono/releases/latest/download/JetBrainsMono-2.304.zip"
JBMONO_ALT_URL="https://github.com/JetBrains/JetBrainsMono/releases/latest/download/JetBrainsMono.zip"
echo ""
echo "[3/3] Downloading JetBrains Mono..."
if curl -sSL -o "$TEMP_DIR/jetbrains-mono.zip" "$JBMONO_URL" 2>/dev/null; then
    echo "  Downloaded versioned archive"
elif curl -sSL -o "$TEMP_DIR/jetbrains-mono.zip" "$JBMONO_ALT_URL" 2>/dev/null; then
    echo "  Downloaded latest archive"
else
    echo "  ! Could not download JetBrains Mono — check GitHub releases"
fi

if [ -f "$TEMP_DIR/jetbrains-mono.zip" ]; then
    unzip -qo "$TEMP_DIR/jetbrains-mono.zip" -d "$TEMP_DIR/jetbrains-mono"

    find_and_copy_jbmono() {
        local src_dir="$TEMP_DIR/jetbrains-mono"
        local weights=("Regular" "Medium" "Bold")
        local weight_nums=("400" "500" "700")

        for i in "${!weights[@]}"; do
            local weight="${weights[$i]}"
            local num="${weight_nums[$i]}"
            local woff2_file
            woff2_file=$(find "$src_dir" -iname "JetBrainsMono-${weight}.woff2" -type f 2>/dev/null | head -1)

            if [ -n "$woff2_file" ]; then
                cp "$woff2_file" "$FONT_DIR/jetbrains-mono-${num}.woff2"
                echo "  - jetbrains-mono-${num}.woff2"
            else
                local ttf_file
                ttf_file=$(find "$src_dir" -iname "JetBrainsMono-${weight}.ttf" -type f 2>/dev/null | head -1)
                if [ -n "$ttf_file" ]; then
                    if command -v woff2_compress &>/dev/null; then
                        woff2_compress "$ttf_file"
                        cp "${ttf_file%.ttf}.woff2" "$FONT_DIR/jetbrains-mono-${num}.woff2"
                        echo "  - jetbrains-mono-${num}.woff2 (converted from TTF)"
                    else
                        cp "$ttf_file" "$FONT_DIR/jetbrains-mono-${num}.ttf"
                        echo "  - jetbrains-mono-${num}.ttf (TTF — run woff2_compress to convert)"
                    fi
                else
                    echo "  ! jetbrains-mono-${num} not found"
                fi
            fi
        done
    }

    find_and_copy_jbmono
fi

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
echo ""
echo "==========================="
echo "Font files in $FONT_DIR:"
echo ""
ls -la "$FONT_DIR/" 2>/dev/null | grep -E '\.(woff2|ttf)$' || echo "  (none found)"
echo ""

WOFF2_COUNT=$(find "$FONT_DIR" -name "*.woff2" 2>/dev/null | wc -l)
TTF_COUNT=$(find "$FONT_DIR" -name "*.ttf" 2>/dev/null | wc -l)

if [ "$WOFF2_COUNT" -ge 14 ]; then
    echo "All $WOFF2_COUNT WOFF2 files present. Ready for production."
elif [ "$TTF_COUNT" -gt 0 ]; then
    echo "$WOFF2_COUNT WOFF2 files, $TTF_COUNT TTF files."
    echo ""
    echo "To convert TTF to WOFF2, install google/woff2:"
    echo "  pip install fonttools brotli"
    echo "Then convert:"
    echo "  for f in $FONT_DIR/*.ttf; do"
    echo "    python3 -c \"from fontTools.ttLib import TTFont; f=TTFont('\$f'); f.flavor='woff2'; f.save('\${f%.ttf}.woff2')\""
    echo "  done"
else
    echo "No font files found. Check the download URLs."
fi
