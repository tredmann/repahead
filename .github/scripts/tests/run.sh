#!/usr/bin/env bash
# Fixture-driven tests for the CVE patch decision scripts.
# Run from anywhere: .github/scripts/tests/run.sh
set -uo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
scripts="$(dirname "$here")"
fixtures="$here/fixtures"
failures=0

# assert_eq <case name> <expected> <actual>
assert_eq() {
  if [ "$2" = "$3" ]; then
    echo "  ok   $1"
  else
    echo "  FAIL $1"
    echo "         expected: $2"
    echo "         actual:   $3"
    failures=$((failures + 1))
  fi
}

# assert_fails <case name> <command...> — passes when the command exits non-zero
assert_fails() {
  name="$1"
  shift
  if "$@" >/dev/null 2>&1; then
    echo "  FAIL $name (expected non-zero exit, got 0)"
    failures=$((failures + 1))
  else
    echo "  ok   $name"
  fi
}

echo "cve-reduce.sh"

assert_eq "collapses duplicate findings and sorts by id" \
  '[{"id":"CVE-2026-31122","pkg":"busybox","severity":"HIGH","installed":"1.37.0-r12","fixed":"1.37.0-r13"},{"id":"CVE-2026-45447","pkg":"openssl","severity":"CRITICAL","installed":"3.5.4-r0","fixed":"3.5.4-r1"}]' \
  "$("$scripts/cve-reduce.sh" "$fixtures/trivy-two-findings.json")"

assert_eq "treats a null Results block as an empty set" \
  '[]' \
  "$("$scripts/cve-reduce.sh" "$fixtures/trivy-clean.json")"

assert_fails "rejects a malformed report" \
  "$scripts/cve-reduce.sh" "$fixtures/trivy-malformed.json"

assert_fails "rejects a missing report" \
  "$scripts/cve-reduce.sh" "$fixtures/does-not-exist.json"

# --- additional cases appended by later tasks ---

echo
if [ "$failures" -eq 0 ]; then
  echo "All cases passed."
  exit 0
fi
echo "$failures case(s) failed."
exit 1
