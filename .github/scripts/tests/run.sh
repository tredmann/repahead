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

echo
echo "cve-decide.sh"

# decide <current fixture> <candidate fixture> <jq filter over the decision object>
decide() {
  "$scripts/cve-decide.sh" "$fixtures/$1" "$fixtures/$2" | jq -r "$3"
}

assert_eq "publishes when every finding is cleared" \
  "true" "$(decide set-cur-crit-high.json set-empty.json '.should_publish')"

assert_eq "publishes when a CRITICAL is cleared and a HIGH remains" \
  "true" "$(decide set-cur-crit-high.json set-cand-high.json '.should_publish')"

assert_eq "reports the surviving HIGH as remaining, not cleared" \
  "CVE-2026-31122" "$(decide set-cur-crit-high.json set-cand-high.json '.remaining[0].id')"

assert_eq "publishes a cleared CRITICAL even when a new finding appeared" \
  "true" "$(decide set-cur-crit-high.json set-cand-high-plus-new.json '.should_publish')"

assert_eq "reports the new finding as introduced" \
  "CVE-2026-55010" "$(decide set-cur-crit-high.json set-cand-high-plus-new.json '.introduced[0].id')"

assert_eq "publishes a cleared HIGH when nothing was introduced" \
  "true" "$(decide set-cur-two-high.json set-cand-one-high.json '.should_publish')"

assert_eq "withholds a cleared HIGH when a new finding appeared" \
  "false" "$(decide set-cur-two-high.json set-cand-one-high-plus-new.json '.should_publish')"

assert_eq "withholds when the rebuild cleared nothing" \
  "false" "$(decide set-cur-two-high.json set-cur-two-high.json '.should_publish')"

assert_eq "clears nothing when the rebuild changed nothing" \
  "0" "$(decide set-cur-two-high.json set-cur-two-high.json '.cleared | length')"

assert_eq "withholds when both sets are empty" \
  "false" "$(decide set-empty.json set-empty.json '.should_publish')"

assert_eq "lists cleared findings in the release notes" \
  "- CVE-2026-45447 (CRITICAL) — openssl 3.5.4-r0 → 3.5.4-r1" \
  "$(decide set-cur-crit-high.json set-cand-high.json '.notes_markdown' | grep 'CVE-2026-45447')"

assert_eq "lists remaining findings in the release notes" \
  "- CVE-2026-31122 (HIGH) — busybox 1.37.0-r12, fix available in 1.37.0-r13" \
  "$(decide set-cur-crit-high.json set-cand-high.json '.notes_markdown' | grep 'CVE-2026-31122')"

assert_eq "explains a no-progress run in the step summary" \
  "### No patch published" \
  "$(decide set-cur-two-high.json set-cur-two-high.json '.summary_markdown' | head -1)"

assert_fails "rejects a malformed finding set" \
  "$scripts/cve-decide.sh" "$fixtures/trivy-malformed.json" "$fixtures/set-empty.json"

assert_fails "rejects a missing finding set" \
  "$scripts/cve-decide.sh" "$fixtures/does-not-exist.json" "$fixtures/set-empty.json"

echo
if [ "$failures" -eq 0 ]; then
  echo "All cases passed."
  exit 0
fi
echo "$failures case(s) failed."
exit 1
