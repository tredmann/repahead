# Private Composer Server

A small PHP service that exposes a private Composer (Packagist-compatible) repository. Publishers drop Release ZIPs into Storage; the service builds and serves the Index. Storage is pluggable via Flysystem (local disk or S3).

**Full documentation:** <https://tredmann.github.io/repahead/>

## Quick start (Docker)

```bash
cp .env.example .env
# edit AUTH_PASS at minimum
AUTH_PASS=$(grep AUTH_PASS .env | cut -d= -f2) docker compose up -d --build
```

The service listens on `http://localhost:8080`.

See the [installation guide](https://tredmann.github.io/repahead/installation/) for `docker run`, local PHP setup, and production configuration.

## Endpoints

| Method | Path | Auth | Purpose |
| ------ | ---- | ---- | ------- |
| GET | `/health` | no | Storage liveness probe |
| GET | `/packages.json` | yes | Root Index — v1 inline `packages` **and** v2 `metadata-url` |
| GET | `/p2/{vendor}/{package}.json` | yes | Composer 2 per-package metadata (p2 document) |
| GET | `/dist/{vendor}/{package}/{version}.zip` | yes | Download a Release ZIP |
| POST | `/rebuild` | yes | Force an Index Rebuild |

Both Composer 1 and Composer 2 point at the same repository URL. Composer 2
uses `metadata-url` to fetch per-package metadata lazily; Composer 1 reads the
inline `packages` map. No client configuration difference is required.

## Working on the docs

Local preview at <http://127.0.0.1:8000> with live reload (requires Python 3.12+):

```bash
composer docs
```

## For contributors

- Domain glossary: [`CONTEXT.md`](CONTEXT.md)
- Architecture decisions: [`docs/adr/`](docs/adr/)
- Full design spec: [`docs/superpowers/specs/2026-05-06-composer-server-design.md`](docs/superpowers/specs/2026-05-06-composer-server-design.md)
