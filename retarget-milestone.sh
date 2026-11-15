#!/usr/bin/env bash
# retarget-milestone.sh - rewrite an old milestone's paths inside the new milestone's folder.
#
#   tools/retarget-milestone.sh <from> <to>            # dry run: shows what would change
#   tools/retarget-milestone.sh <from> <to> --apply    # writes the changes
#
# Example: tools/retarget-milestone.sh milestone2 milestone3 --apply
set -euo pipefail

FROM="${1:?usage: $0 <from> <to> [--apply]}"
TO="${2:?usage: $0 <from> <to> [--apply]}"
APPLY=0
[[ "${3:-}" == "--apply" ]] && APPLY=1

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SCOPE="$ROOT/$TO"
[[ -d "$SCOPE" ]] || { echo "[!] $SCOPE does not exist"; exit 1; }

# Ordered rules: most specific first. Format: old|new
# Structural moves (milestone2/backend/... -> milestone3/be/...) come before the generic rename.
RULES=(
    "$FROM/backend/CocktailBackend|$TO/be/CocktailBackend"
    "$FROM/backend/BackendFrontend|$TO/be/BackendFrontend"
    "$FROM/backend/BackendDatabase|$TO/BackendDatabase"
    "$FROM/backend/systemd|$TO/be/systemd"
    "$FROM/backend|$TO/be"
    "$FROM|$TO"
)

GREP_OPTS=(-rIl --exclude-dir=node_modules --exclude-dir=vendor \
           --exclude-dir=bin --exclude-dir=obj --exclude-dir=.git)

mapfile -t FILES < <(grep "${GREP_OPTS[@]}" -- "$FROM" "$SCOPE" || true)

if [[ ${#FILES[@]} -eq 0 ]]; then
    echo "No references to '$FROM' found in $SCOPE."
    exit 0
fi

echo "Files referencing '$FROM' in $TO:"
for f in "${FILES[@]}"; do
    echo "  ${f#"$ROOT/"}"
    grep -nF -- "$FROM" "$f" | sed 's/^/      /'
done

if [[ $APPLY -eq 0 ]]; then
    echo
    echo "Dry run only. Re-run with --apply to write these changes."
    exit 0
fi

for rule in "${RULES[@]}"; do
    old="${rule%%|*}"
    new="${rule#*|}"
    for f in "${FILES[@]}"; do
        # Escape characters that are special in sed patterns and replacements.
        esc_old=$(printf '%s' "$old" | sed 's/[.[\*^$/]/\\&/g')
        esc_new=$(printf '%s' "$new" | sed 's/[&/\]/\\&/g')
        sed -i "s/$esc_old/$esc_new/g" "$f"
    done
done

echo
echo "Applied. Remaining '$FROM' references in $TO:"
grep "${GREP_OPTS[@]}" -- "$FROM" "$SCOPE" || echo "  none"
echo
echo "Review with: git diff -- $TO"
