#!/usr/bin/env bash
# Emit `composer update` arguments that hold every locked package to its own
# current major version.
#
#   composer-pins.sh <composer.lock>
#
# Prints `--with` and `<pkg>:^<version>` on alternating lines, so the caller can
# read the output into an array without word-splitting. The output ends with a
# newline: a `while read` loop drops a final line that lacks one, which would
# leave a dangling `--with` with no value.
#
# ^<version> is the right pin shape for both cases. Composer reads ^7.10.0 as
# >=7.10.0 <8.0.0, and ^0.3.0 as >=0.3.0 <0.4.0 - so 0.x packages, where the
# minor segment carries breaking changes, need no special case.
#
# Packages whose version does not start with a digit (dev-main), or that
# carries a -dev suffix (1.0.x-dev — this does start with a digit but is not
# a real version Composer's parser accepts in a ^-pin; ^1.0.x-dev is rejected
# outright), are skipped: there is no meaningful major to pin them to.
set -euo pipefail

if [ "$#" -ne 1 ]; then
  echo "usage: composer-pins.sh <composer.lock>" >&2
  exit 2
fi

lock="$1"

if [ ! -f "$lock" ]; then
  echo "composer-pins: no such lock file: $lock" >&2
  exit 2
fi

jq -r '
  ((.packages // []) + (."packages-dev" // []))
  | .[]
  | .version as $v
  | ($v | sub("^v"; "")) as $clean
  | select(($clean | test("^[0-9]")) and ($clean | test("-dev") | not))
  | "--with\n\(.name):^\($clean)"
' "$lock"
