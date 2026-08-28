#!/usr/bin/env bash
# Classify what changed between two composer.lock files.
#
#   composer-lock-diff.sh <before.lock> <after.lock>
#
# Prints a JSON array of {pkg, from, to, kind} sorted by pkg, where kind is
# one of: major, minor, added, removed. Packages whose version did not change
# are omitted.
#
# The major key is the first dot-separated segment, except when that segment is
# "0", where it becomes "0.<minor>" - 0.x releases carry breaking changes in the
# minor position, and composer's caret operator treats them the same way.
#
# A version on either side that does not start with a digit (dev-main,
# 1.0.x-dev) is classified major, so anything unparseable needs a human rather
# than being waved through as a safe upgrade.
set -euo pipefail

if [ "$#" -ne 2 ]; then
  echo "usage: composer-lock-diff.sh <before.lock> <after.lock>" >&2
  exit 2
fi

before="$1"
after="$2"

for f in "$before" "$after"; do
  if [ ! -f "$f" ]; then
    echo "composer-lock-diff: no such lock file: $f" >&2
    exit 2
  fi
done

jq -c -n --slurpfile before "$before" --slurpfile after "$after" '
  def versions:
    ((.packages // []) + (."packages-dev" // []))
    | map({key: .name, value: (.version | sub("^v"; ""))})
    | from_entries;

  def major_key:
    if test("^[0-9]") | not then null
    elif startswith("0.") then (split(".") | .[0] + "." + (.[1] // "0"))
    else (split(".") | .[0])
    end;

  ($before[0] | versions) as $b |
  ($after[0]  | versions) as $a |

  [ ( $a | keys_unsorted[] | select($b[.] == null)
        | {pkg: ., from: "", to: $a[.], kind: "added"} ),
    ( $b | keys_unsorted[] | select($a[.] == null)
        | {pkg: ., from: $b[.], to: "", kind: "removed"} ),
    ( $a | keys_unsorted[]
        | select($b[.] != null and $b[.] != $a[.])
        | . as $p
        | ($b[$p] | major_key) as $bk
        | ($a[$p] | major_key) as $ak
        | {pkg: $p, from: $b[$p], to: $a[$p],
           kind: (if $bk == null or $ak == null or $bk != $ak
                  then "major" else "minor" end)} )
  ]
  | sort_by(.pkg)
'
