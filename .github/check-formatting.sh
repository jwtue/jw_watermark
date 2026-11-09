#!/bin/sh
# Guards the formatting conventions declared in .editorconfig.
#
# Run it locally before pushing:  sh .github/check-formatting.sh
# The Formatting workflow runs the same script on every push and pull request.
#
# It deliberately checks only whitespace — indentation characters, trailing
# whitespace, final newline and line endings. It is not a full PSR-12 linter;
# see AGENTS.md for why.
set -u
status=0

fail() {
    echo "::error file=$1,line=$2::$3"
    echo "  $1:$2 — $3"
    status=1
}

# PHP files: indent with spaces, never tabs.
for f in $(git ls-files '*.php'); do
    awk -v F="$f" '/^\t/ { print F ":" NR }' "$f" | while IFS=: read -r file line; do
        echo "TAB $file $line"
    done
done > .fmt-tabs.tmp
while read -r kind file line; do
    [ -n "${kind:-}" ] || continue
    fail "$file" "$line" "leading tab — use 4 spaces (see .editorconfig)"
done < .fmt-tabs.tmp
rm -f .fmt-tabs.tmp

# No trailing whitespace, except in Markdown (hard line breaks).
for f in $(git ls-files '*.php' '*.yml' '*.yaml' '*.json' '*.sh' '.editorconfig' '.gitattributes'); do
    [ -f "$f" ] || continue
    awk -v F="$f" '/[ \t]+$/ { print F ":" NR }' "$f"
done > .fmt-trail.tmp
while IFS=: read -r file line; do
    [ -n "${file:-}" ] || continue
    fail "$file" "$line" "trailing whitespace"
done < .fmt-trail.tmp
rm -f .fmt-trail.tmp

# Exactly one final newline.
for f in $(git ls-files '*.php' '*.yml' '*.yaml' '*.json' '*.md' '*.sh' '.editorconfig' '.gitattributes'); do
    [ -f "$f" ] || continue
    if [ -n "$(tail -c 1 "$f")" ]; then
        fail "$f" "$(wc -l < "$f" | tr -d ' ')" "no newline at end of file"
    fi
done

# Line endings in the repository must be LF. `git ls-files --eol` reports what is
# stored in the index, so a Windows checkout with core.autocrlf=true is fine.
git ls-files --eol | while read -r idx work attr path; do
    case "$idx" in
        i/crlf|i/mixed|i/crlf*) echo "EOL $path $idx" ;;
    esac
done > .fmt-eol.tmp
while read -r kind path idx; do
    [ -n "${kind:-}" ] || continue
    fail "$path" 1 "stored with $idx line endings — the repository is LF only"
done < .fmt-eol.tmp
rm -f .fmt-eol.tmp

if [ "$status" -eq 0 ]; then
    echo "Formatting OK — no tabs, no trailing whitespace, final newlines present, LF throughout."
fi
exit "$status"
