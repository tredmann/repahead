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

# assert_exit_code <case name> <expected exit code> <command...> — passes
# when the command exits with exactly the expected code.
assert_exit_code() {
  name="$1"
  expected="$2"
  shift 2
  "$@" >/dev/null 2>&1
  actual="$?"
  if [ "$actual" = "$expected" ]; then
    echo "  ok   $name"
  else
    echo "  FAIL $name (expected exit $expected, got $actual)"
    failures=$((failures + 1))
  fi
}

echo "cve-reduce.sh"

assert_eq "collapses duplicate findings and sorts by id" \
  '[{"id":"CVE-2026-31122","pkg":"busybox","severity":"HIGH","installed":"1.37.0-r12","fixed":"1.37.0-r13"},{"id":"CVE-2026-45447","pkg":"openssl","severity":"CRITICAL","installed":"3.5.4-r0","fixed":"3.5.4-r1"}]' \
  "$("$scripts/cve-reduce.sh" "$fixtures/trivy-two-findings.json")"

assert_eq "reduces a realistic scanned-and-clean report to an empty set" \
  '[]' \
  "$("$scripts/cve-reduce.sh" "$fixtures/trivy-clean.json")"

assert_fails "rejects a malformed report" \
  "$scripts/cve-reduce.sh" "$fixtures/trivy-malformed.json"

assert_exit_code "rejects a missing report" 2 \
  "$scripts/cve-reduce.sh" "$fixtures/does-not-exist.json"

assert_exit_code "rejects a wrong argument count" 2 \
  "$scripts/cve-reduce.sh"

assert_exit_code "rejects a report Trivy could not analyze at all" 3 \
  "$scripts/cve-reduce.sh" "$fixtures/trivy-unanalyzable.json"

echo
echo "cve-audit-reduce.sh"

# audit_reduce <audit fixture> <lock fixture>
audit_reduce() {
  "$scripts/cve-audit-reduce.sh" "$fixtures/$1" "$fixtures/$2"
}

assert_eq "keeps HIGH and CRITICAL advisories and drops MEDIUM" \
  '[{"id":"CVE-2026-54133","pkg":"mtdowling/jmespath.php","severity":"CRITICAL","installed":"2.8.0","fixed":""},{"id":"CVE-2026-69246","pkg":"guzzlehttp/guzzle","severity":"HIGH","installed":"7.10.0","fixed":""}]' \
  "$(audit_reduce audit-two-advisories.json composer-lock-sample.json)"

assert_eq "treats an unrated advisory as HIGH rather than dropping it" \
  "HIGH" \
  "$(audit_reduce audit-null-severity.json composer-lock-sample.json | jq -r '.[0].severity')"

assert_eq "falls back to advisoryId when an advisory carries no CVE" \
  "PKSA-nocve-0002" \
  "$(audit_reduce audit-no-cve.json composer-lock-sample.json | jq -r '.[0].id')"

assert_eq "resolves installed versions from packages-dev as well as packages" \
  "10.5.63" \
  "$(audit_reduce audit-no-cve.json composer-lock-sample.json | jq -r '.[0].installed')"

assert_eq "reduces an empty advisories object to an empty set" \
  '[]' \
  "$(audit_reduce audit-clean-object.json composer-lock-sample.json)"

assert_eq "reduces an empty advisories array to an empty set" \
  '[]' \
  "$(audit_reduce audit-clean-array.json composer-lock-sample.json)"

assert_exit_code "rejects a report with no advisories key" 3 \
  "$scripts/cve-audit-reduce.sh" "$fixtures/audit-no-advisories-key.json" "$fixtures/composer-lock-sample.json"

assert_fails "rejects a malformed report" \
  "$scripts/cve-audit-reduce.sh" "$fixtures/audit-malformed.json" "$fixtures/composer-lock-sample.json"

assert_exit_code "rejects a missing report" 2 \
  "$scripts/cve-audit-reduce.sh" "$fixtures/does-not-exist.json" "$fixtures/composer-lock-sample.json"

assert_exit_code "rejects a missing lock file" 2 \
  "$scripts/cve-audit-reduce.sh" "$fixtures/audit-clean-object.json" "$fixtures/does-not-exist.json"

assert_exit_code "rejects a wrong argument count" 2 \
  "$scripts/cve-audit-reduce.sh" "$fixtures/audit-clean-object.json"

echo
echo "cve-merge.sh"

# merge <set fixture a> <set fixture b>
merge() {
  "$scripts/cve-merge.sh" "$fixtures/$1" "$fixtures/$2"
}

assert_eq "collapses the same finding reported by both sensors into one record" \
  "1" \
  "$(merge set-audit-critical.json set-trivy-overlap.json | jq 'length')"

assert_eq "keeps CRITICAL when the two sensors disagree on severity" \
  "CRITICAL" \
  "$(merge set-audit-critical.json set-trivy-overlap.json | jq -r '.[0].severity')"

assert_eq "prefers the record carrying a fix version" \
  "2.9.1" \
  "$(merge set-audit-critical.json set-trivy-overlap.json | jq -r '.[0].fixed')"

assert_eq "is commutative for severity and fix version" \
  "CRITICAL 2.9.1" \
  "$(merge set-trivy-overlap.json set-audit-critical.json | jq -r '.[0].severity + " " + .[0].fixed')"

assert_eq "unions disjoint sets and sorts by id" \
  '[{"id":"CVE-2026-31122","pkg":"busybox","severity":"HIGH","installed":"1.37.0-r12","fixed":"1.37.0-r13"},{"id":"CVE-2026-45447","pkg":"openssl","severity":"CRITICAL","installed":"3.5.4-r0","fixed":"3.5.4-r1"},{"id":"CVE-2026-54133","pkg":"mtdowling/jmespath.php","severity":"CRITICAL","installed":"2.8.0","fixed":""}]' \
  "$(merge set-cur-crit-high.json set-audit-critical.json)"

assert_eq "merges two empty sets to an empty set" \
  '[]' \
  "$(merge set-empty.json set-empty.json)"

assert_eq "merging with an empty set is an identity" \
  '[{"id":"CVE-2026-54133","pkg":"mtdowling/jmespath.php","severity":"CRITICAL","installed":"2.8.0","fixed":""}]' \
  "$(merge set-audit-critical.json set-empty.json)"

assert_fails "rejects a malformed finding set" \
  "$scripts/cve-merge.sh" "$fixtures/audit-malformed.json" "$fixtures/set-empty.json"

assert_exit_code "rejects a missing finding set" 2 \
  "$scripts/cve-merge.sh" "$fixtures/does-not-exist.json" "$fixtures/set-empty.json"

assert_exit_code "rejects a wrong argument count" 2 \
  "$scripts/cve-merge.sh" "$fixtures/set-empty.json"

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

assert_eq "publishes when a cleared CRITICAL coincides with an introduced CRITICAL" \
  "true" "$(decide set-cur-crit-high.json set-cand-high-plus-new-critical.json '.should_publish')"

assert_eq "reports the introduced CRITICAL as introduced, not cleared" \
  "CVE-2026-77001" "$(decide set-cur-crit-high.json set-cand-high-plus-new-critical.json '.introduced[0].id')"

assert_eq "omits the newly-introduced heading in notes when nothing was introduced" \
  "0" "$(decide set-cur-crit-high.json set-cand-high.json '.notes_markdown' | grep -c '### Newly introduced')"

assert_fails "rejects a malformed finding set" \
  "$scripts/cve-decide.sh" "$fixtures/trivy-malformed.json" "$fixtures/set-empty.json"

assert_exit_code "rejects a missing finding set" 2 \
  "$scripts/cve-decide.sh" "$fixtures/does-not-exist.json" "$fixtures/set-empty.json"

assert_exit_code "rejects a wrong argument count" 2 \
  "$scripts/cve-decide.sh" "$fixtures/set-empty.json"

echo
echo "end-to-end round trip (cve-reduce.sh | GITHUB_OUTPUT-shaped roundtrip | cve-decide.sh)"

e2e_dir="$(mktemp -d)"
trap 'rm -rf "$e2e_dir"' EXIT

# Simulate what the workflow does: capture cve-reduce.sh's stdout into a shell
# variable (as a job output would), round-trip it through a file the way
# printf '%s' "$VAR" > file / $GITHUB_OUTPUT does, then feed that file to
# cve-decide.sh. This is the exact shape that runs in CI.
current_findings="$("$scripts/cve-reduce.sh" "$fixtures/trivy-two-findings.json")"
printf '%s' "$current_findings" > "$e2e_dir/current.json"
"$scripts/cve-reduce.sh" "$fixtures/trivy-clean.json" > "$e2e_dir/candidate.json"

e2e_decision="$("$scripts/cve-decide.sh" "$e2e_dir/current.json" "$e2e_dir/candidate.json")"

assert_eq "round trip: real Trivy reports produce a publish decision" \
  "true" "$(jq -r '.should_publish' <<<"$e2e_decision")"

assert_eq "round trip: both real findings are reported cleared" \
  "2" "$(jq -r '.cleared | length' <<<"$e2e_decision")"

echo
if [ "$failures" -eq 0 ]; then
  echo "All cases passed."
  exit 0
fi
echo "$failures case(s) failed."
exit 1
