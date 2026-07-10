# RepAhead — Domain Glossary

## Listing

The flat sequence of `(vendor, package, version, path, size, lastModified)` tuples produced by scanning Storage. Produced by the `Catalog` component (class name kept as-is; the output is the Listing, not the component). Derived entirely from the storage layout — no ZIP is opened to produce it. Represented in code as `Release[]` plus a SHA-256 Listing Fingerprint.

## Storage

The pluggable store where ZIPs live. Accessed read-only through Flysystem. Configured via `STORAGE_DSN` as either a local path (`local:./zips`) or an S3 bucket/prefix (`s3:bucket/prefix`). The server never writes to Storage.

## Cache

The local-disk layer holding derived files: `packages.json` (the current Index), `manifest.hash` (SHA-256 of the last Listing used to build it), and `.rebuild.lock` (concurrency sentinel). Always local regardless of the Storage backend.

## Package

A named unit of code identified by `vendor/name` (e.g. `acme/billing`). Has no version of its own; it is the stable identity across all Releases of the same library.

## Release

A specific versioned artifact: one ZIP file for a particular version of a Package (e.g. `acme/billing:1.2.0`). A Release is the atomic unit in Storage and in the Listing. `version` is an attribute of a Release, not a first-class entity.

## Dist

The download descriptor object synthesized for each Release entry in the Index. Contains `type` ("zip"), `url` (the `/dist/…` route on this server), and `shasum` (the SHA-1 of the ZIP bytes). Copied verbatim from the Composer protocol. Not present in Storage — this server synthesizes it during every Index build.

## Shasum

The SHA-1 hash of a Release's ZIP file bytes, stored in the `dist.shasum` field of the Index. Used by Composer to verify download integrity. Computed during Index build by `ZipMetadata`. Distinct from the Listing Fingerprint, which is SHA-256 of Listing metadata and is only used internally for cache invalidation.

## Publisher

The actor who places Release ZIPs into Storage — a developer, a CI pipeline, or any out-of-band process. A Publisher may also call `POST /rebuild` to make a freshly Published Release immediately available without waiting for the TTL to expire.

## Consumer

The actor who installs packages using this server as a Composer repository. Reads the Index via `GET /packages.json` and downloads Releases via `GET /dist/…`. Interacts exclusively through the HTTP API; never touches Storage directly.

## Publish

The act of placing a Release ZIP into Storage. Happens entirely outside this server — via `scp`, `aws s3 cp`, a CI artifact step, or any other out-of-band mechanism. The server never writes to Storage; it only discovers newly Published Releases the next time it scans.

## Rejected Release

A Release that is excluded from the Index during a build because it is either unreadable (corrupt ZIP, stream failure) or invalid (missing `composer.json`, malformed JSON, or `name` field mismatching the folder path). The cause is logged; the build continues with the remaining Releases. The count of Rejected Releases is reported in the `POST /rebuild` response.

## Listing Fingerprint

A SHA-256 hash of the sorted Listing content (`path|size|lastModified` per Release). Stored in `cache/manifest.hash`. Used to detect whether Storage has changed since the last Index build, independently of the TTL. If the current Listing produces the same Fingerprint as the stored one, the cached Index is still valid.

## Index

The rendered `packages.json` document that Composer consumes. Built from the Listing by opening each ZIP, reading its embedded `composer.json`, and synthesizing a `dist` block. Represented in code as `PackagesJsonResult`.

## Listing TTL

The time window during which a fresh Storage listing is skipped and the Cached Index is served straight from disk. Configured via `LISTING_TTL_SECONDS` (default `30`); `0` disables it entirely. Distinct from the Listing Fingerprint, which is a content check that runs once the TTL has expired — the TTL bounds how often Storage is listed, the Fingerprint decides whether a listing should trigger a Rebuild.

## Rebuild

The act of re-deriving the Index from a fresh Listing: opening each Release ZIP, reading its embedded `composer.json`, and synthesizing a Dist block per Release. Triggered either by the Listing Fingerprint changing after a TTL expiry, or by an explicit `POST /rebuild` from a Publisher. Reports Package count, Release count, Rejected-Release count, and wall-clock duration in its response.

## Metadata URL (p2 document)

The Composer v2 lazy-loading API. The root Index advertises
`metadata-url: <baseUrl>/p2/%package%.json`; a Consumer running Composer 2
substitutes `%package%` and fetches one **p2 document** per required Package
from `GET /p2/{vendor}/{package}.json`. A p2 document has the shape
`{"packages":{"vendor/package":[ …version objects… ]}}` — the versions are a
**list** (newest first), unlike the v1 inline map keyed by version. Built at
Rebuild time and stored in the Cache as the single `p2.json` manifest. A
request for an unknown Package (including any `~dev` probe, since this server
holds only tagged Releases) returns 404. Composer 1 ignores `metadata-url` and
reads the inline `packages` map instead.

## Available Packages

The flat, sorted list of every Package name (`available-packages`) included in
the root Index. Lets a Composer 2 Consumer know exactly which Packages exist so
it never probes unknown names. Derived from the Listing during each Index
build.
