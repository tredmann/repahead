# Composer v2 metadata-url Support (side-by-side with v1 inline)

**Date:** 2026-07-10
**Status:** Approved
**Scope:** `app/Catalog/PackagesJson.php`, `app/Cache.php`, `app/Http/Controller.php`,
`app/App.php` (routing), plus tests, `CONTEXT.md`, and `README`.

## Problem

`GET /packages.json` returns a single document with every Package and every
Release inlined under the `packages` key, keyed by version string
(`packages[name][version] = {…}`). This is the classic **Composer v1 inline
format**.

Composer 2 can still *read* that format — it is backward compatible — so v2
clients are not broken today. What is missing is native v2 behavior: the
**metadata-url ("p2") API**, where the root document advertises a
`metadata-url` template and Composer lazily fetches per-package metadata from
`/p2/vendor/package.json` on demand instead of downloading the entire catalog
up front. That is the real upgrade — scalability and idiomatic v2 resolution.

## Goal

Serve **both** formats from one repository, side-by-side:

- **Composer 1** clients read the inline `packages` map. Unchanged behavior.
- **Composer 2** clients use `metadata-url` and fetch per-package p2 documents.

The root `packages.json` advertises both. Composer 2 prefers `metadata-url`
and ignores the inline `packages`; Composer 1 ignores `metadata-url` and reads
the inline `packages`. Each client picks its own path.

## Decisions

Locked during brainstorming:

1. **Both formats side-by-side** — not a replacement of the v1 format.
2. **p2 files are built at Rebuild time**, cached alongside `packages.json`,
   keyed by the existing Listing Fingerprint. A request just serves a cached
   file. This is consistent with the existing two-tier cache model.
3. **Tagged releases only.** Storage holds semver-style versions
   (e.g. `1.2.0`). We generate `/p2/vendor/package.json`; the `~dev` variant
   naturally 404s (see below). No dev/branch handling.
4. **No new dependency; omit `version_normalized`.** For standard tags,
   Composer 2's own loader normalizes the `version` field, so we do not pull
   in `composer/semver`. This matches how the v1 inline format already
   behaves.
5. **No minified `composer/2.0` diff format.** It is an optional bandwidth
   optimization; YAGNI for a private repo.

## Design

### Root Index document (`PackagesJson::build`)

Add two keys to the root document; keep the inline `packages` map untouched:

- `"metadata-url": "<baseUrl>/p2/%package%.json"` — absolute, matching how
  `dist.url` is already constructed from `baseUrl`, for consistency.
- `"available-packages": [ …sorted package names… ]` — the full flat list of
  package names, so Composer 2 knows exactly what exists and never probes
  unknown names.

`available-packages` is the simplest correct option. The alternatives
(`available-package-patterns`, a `list` endpoint) only matter at very large
scale and are out of scope for a bounded private catalog.

### Per-package p2 document

The one real format difference from v1: versions become an **array**, not a
version-keyed map.

```json
{ "packages": { "vendor/package": [ { "…version-obj…" }, { "…version-obj…" } ] } }
```

Each version object is the *same* per-version data already synthesized for the
inline format — the embedded `composer.json` plus the injected `version` and
`dist` block. Only the container shape changes (array instead of a map keyed
by version). Versions are ordered newest-first by the existing sort, reversed.

### Build output (`PackagesJson::build`)

`build()` currently returns a `PackagesJsonResult` carrying the root JSON and
counts. Extend it with a `p2Json` string — the encoded p2 manifest
(`{ "vendor/package": [ …version objects… ] }`). The existing counts
(packages, versions, skipped) are unchanged and continue to drive the
`POST /rebuild` summary.

The name/version validation and Rejected-Release handling are unchanged: a
Release that is rejected never reaches either the inline `packages` map or its
p2 document.

### Cache (`app/Cache.php`)

Today the Cache persists a single `packages.json` (plus `manifest.hash` and
`.rebuild.lock`). Generalize it to persist a set of **named artifacts** under
one Listing Fingerprint. A Rebuild atomically writes, then records the
Fingerprint last:

- `packages.json` (the root Index)
- `p2.json` — a single **p2 manifest**: a JSON object mapping each package
  name to its list of version objects (`{ "vendor/package": [ {…}, … ] }`).

A single manifest file (rather than one file per package on disk) keeps the
write atomic and needs no stale-file cleanup: a package removed from Storage
simply disappears from the new `p2.json`, so its endpoint 404s naturally.
Because both artifacts share the single Listing Fingerprint and the Fingerprint
is written last, a reader gating on the Fingerprint never sees a mix of old and
new files — `p2.json` is always mutually consistent with `packages.json`.

Concretely, `rebuild()` takes a build closure returning a
`array<string,string>` map of artifact-name → content, writes each atomically,
then writes the hash. `readIfFresh()` and `readIfHashMatches()` take the
artifact name to read. The existing file-locking is unchanged.

The existing file-locking and two-tier invalidation (Listing TTL bounds
re-listing; Fingerprint decides whether to Rebuild) are unchanged and now
cover the p2 files as well.

### Routing + Controller

Add one auth-protected route:

```
GET /p2/{vendor}/{package}.json   → Controller::metadata()
```

`metadata()` runs the *same* freshness pipeline as `packages()`
(`readIfFresh` → `scan` → Fingerprint match → `rebuild`) to guarantee the p2
manifest is current, then reads `p2.json`, looks up the requested package, and
serves `{"packages":{"vendor/package":[ … ]}}`.

- Unknown package → **404**.
- A `~dev` request (`/p2/vendor/package~dev.json`) resolves to package name
  `vendor/package~dev`, which does not exist → **404**. This is the correct
  "no dev versions" signal to Composer 2, and needs no special handling.

The new route must explicitly chain `.middleware($auth)` in `App::router()`,
per the per-route auth convention. `GET /health` remains the only public
route.

## Data flow

**Composer 2 client:**
`GET /packages.json` → sees `metadata-url` → for each required package
`GET /p2/vendor/package.json` (plus `~dev`, which 404s) → resolves the
version from the array → downloads via the existing `GET /dist/…` route.

**Composer 1 client:**
`GET /packages.json` → reads the inline `packages` map → downloads via
`GET /dist/…`. Entirely unchanged.

## Error handling

- Unknown package (including any `~dev` request) → 404 via `SafeJsonStrategy`,
  same shape as the current `/dist` 404.
- Storage unavailable during the freshness check → 503, mirroring the existing
  `packages()` handler.
- Rejected Releases are excluded upstream during the build, so they never
  appear in either the inline map or a p2 document.

## Out of scope

- Replacing or removing the v1 inline format.
- Dev/branch versions and the `~dev` p2 document (tagged-only repo).
- `version_normalized` and any `composer/semver` dependency.
- The minified `composer/2.0` diff format.
- `available-package-patterns` and a `list` endpoint.

## Testing / Verification

- **`PackagesJsonTest`**: root document now includes `metadata-url` and
  `available-packages`; per-package p2 documents use the array-of-versions
  shape; name/version validation and Rejected-Release exclusion still hold.
- **`ControllerTest`**: `/p2/vendor/package.json` returns the package's
  versions; unknown package and `~dev` requests return 404; the route requires
  auth.
- **`CacheTest`**: `packages.json` and `p2.json` are written atomically under a
  single Fingerprint; named-artifact reads (`readIfFresh`/`readIfHashMatches`)
  return the right file; a stale/partial state is never served.
- **EndToEnd / Smoke**: the full v2 resolve path
  (`packages.json` → `p2` → `dist`) and the unchanged v1 path.
- **Quality pipeline** (`composer rector` → `pint` → `test` → `stan`) green,
  per CLAUDE.md.

## Docs

- **`CONTEXT.md`**: add domain terms — **Metadata URL** / **p2 document**
  (the per-package v2 metadata file) and **Available Packages** (the flat
  name list in the root document).
- **`README`**: document the `GET /p2/{vendor}/{package}.json` endpoint and
  the dual-format behavior.
- **ADR**: add one if the cache layout change is deemed material (a new `p2/`
  subtree under the Cache dir).
