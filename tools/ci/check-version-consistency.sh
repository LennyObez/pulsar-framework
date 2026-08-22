#!/usr/bin/env bash
#
# Pre-merge assertion that the version reported by `Pulsar\Core\Version`
# at runtime matches the `version` field declared in composer.json.
#
# The two values are edited in different files by different steps of a
# release, so nothing but this check couples them. Without it a release
# PR can bump composer.json alone and the framework reports a version
# that no longer exists.
#
# Usage:
#   tools/ci/check-version-consistency.sh
#
# Exits non-zero with a diagnostic on mismatch. CI calls this in the
# php-quality job; release PRs that flip composer.json must flip the
# Version constant in the same commit or the merge is blocked.

set -euo pipefail

repo_root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$repo_root"

composer_version="$(php -r '
    $data = json_decode(file_get_contents("composer.json"), true);
    if (!is_array($data) || !isset($data["version"]) || !is_string($data["version"])) {
        fwrite(STDERR, "composer.json: missing or invalid \"version\" field\n");
        exit(2);
    }
    echo $data["version"];
')"

# Build the runtime version string from the Version constants alone --
# without booting the framework. We extract `MAJOR` / `MINOR` / `PATCH`
# (ints) and `PRERELEASE_SUFFIX` (string) from the source file via
# tolerant regexes so the check works in any environment where
# `composer install` has not yet run. Production (`Version::full()`)
# reads composer's installed metadata which is the value we are actually
# trying to validate, so we cannot bootstrap the autoloader and call
# the production code path here.
runtime_version="$(php -r '
    $src = file_get_contents("src/Core/Version.php");
    if ($src === false) {
        fwrite(STDERR, "could not read src/Core/Version.php\n");
        exit(2);
    }
    if (!preg_match("/public const int MAJOR\s*=\s*(\d+)/", $src, $major)) {
        fwrite(STDERR, "src/Core/Version.php: MAJOR constant not parseable\n");
        exit(2);
    }
    if (!preg_match("/public const int MINOR\s*=\s*(\d+)/", $src, $minor)) {
        fwrite(STDERR, "src/Core/Version.php: MINOR constant not parseable\n");
        exit(2);
    }
    if (!preg_match("/public const int PATCH\s*=\s*(\d+)/", $src, $patch)) {
        fwrite(STDERR, "src/Core/Version.php: PATCH constant not parseable\n");
        exit(2);
    }
    if (!preg_match("/public const string PRERELEASE_SUFFIX\s*=\s*\x27([^\x27]*)\x27/", $src, $suffix)) {
        fwrite(STDERR, "src/Core/Version.php: PRERELEASE_SUFFIX constant not parseable\n");
        exit(2);
    }
    echo $major[1] . "." . $minor[1] . "." . $patch[1] . $suffix[1];
')"

if [ "$composer_version" != "$runtime_version" ]; then
    cat >&2 <<EOF
Version constants drift detected:

    composer.json::version       = "$composer_version"
    src/Core/Version.php (full)  = "$runtime_version"

Pulsar's runtime Version reads composer's installed metadata, which
becomes the value reported to extensions, audit logs, telemetry, and
public diagnostics. A mismatch with composer.json::version means a
release PR bumped one without the other and the framework will report
a wrong version to every consumer.

Edit src/Core/Version.php in the same commit as composer.json so both
values move together.
EOF
    exit 1
fi

echo "version-consistency: composer.json (\"$composer_version\") matches src/Core/Version.php (\"$runtime_version\")"
