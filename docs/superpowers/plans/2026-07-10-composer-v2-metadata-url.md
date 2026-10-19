# Composer v2 metadata-url Support Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Serve the Composer v2 metadata-url ("p2") API side-by-side with the existing v1 inline `packages.json`, so v2 clients lazily fetch per-package metadata while v1 clients are unaffected.

**Architecture:** The root `packages.json` gains `metadata-url` and `available-packages` keys next to the untouched inline `packages` map. A single `p2.json` manifest (package name → version-object list) is built at Rebuild time and cached under the existing Listing Fingerprint. A new auth-protected `GET /p2/{vendor}/{package}.json` route serves one package's metadata from that manifest. The `Cache` is generalized to persist a set of named artifacts atomically under one Fingerprint.

**Tech Stack:** PHP 8.2+, League Route (FastRoute), Laminas Diactoros (PSR-7), Flysystem, PHPUnit, PHPStan level 8, Pint (PSR-12), Rector.

## Global Constraints

- PHP 8.2+, `declare(strict_types=1)` in every file.
- Minimum code that solves the problem. Touch only what you must.
- After every code change run, in order, stopping on first failure:
  `composer rector` → `composer pint` → `composer test` → `composer stan`.
  Do not suppress findings with `@phpstan-ignore` or hand-format around Pint.
- PSR-4: `RepAhead\` → `app/`, `RepAhead\Tests\` → `tests/`.
- Tests mirror `app/` layout; cross-cutting tests stay at `tests/` root.
- New protected routes must explicitly chain `.middleware($auth)` in `App::router()`. `GET /health` is the only public route.
- `dist.url` and `metadata-url` are absolute, built from `baseUrl`.
- No new Composer dependency. No `version_normalized`. No minified `composer/2.0` format.
- Use the domain vocabulary from `CONTEXT.md` in code, comments, and commits.

---

### Task 1: p2 manifest + v2 root keys in `PackagesJson`

Add the v2 root keys (`metadata-url`, `available-packages`) and a `p2Json` manifest to the build output. This task is additive: the existing `->json` inline `packages` map is unchanged, so all current callers keep working.

**Files:**
- Modify: `app/Catalog/PackagesJson.php` (the `PackagesJsonResult` value object at lines 11-20, and `PackagesJson::build()` at lines 32-98)
- Test: `tests/Catalog/PackagesJsonTest.php`

**Interfaces:**
- Consumes: `Release` (`->fullName()`, `->version`, `->path`), `ZipMeta` (`->composerJson`, `->sha1`) — unchanged.
- Produces: `PackagesJsonResult` gains `public string $p2Json` as its **first** constructor parameter (before `$json`), so it reads `new PackagesJsonResult(p2Json: …, json: …, packagesCount: …, versionsCount: …, skippedCount: …)`. `p2Json` is a JSON object string mapping each package name to a JSON array of its version objects, newest version first: `{"acme/billing":[{…1.3.0…},{…1.2.0…}]}`. Empty manifest encodes as `{}`.

- [ ] **Step 1: Add failing assertions for the v2 root keys and p2 manifest**

Append these two methods to `tests/Catalog/PackagesJsonTest.php` (they reuse the existing `entry()` helper):

```php
    public function testRootAdvertisesV2MetadataUrlAndAvailablePackages(): void
    {
        $entries = [
            $this->entry('acme', 'billing', '1.2.0'),
            $this->entry('zeta', 'tools', '0.1.0'),
        ];
        $reader = fn (Release $e): ZipMeta => new ZipMeta(
            ['name' => "{$e->vendor}/{$e->package}", 'version' => $e->version, 'type' => 'library'],
            str_repeat('a', 40)
        );
        $result = (new PackagesJson(new NullLogger()))->build($entries, $reader, 'https://example.com');
        $decoded = json_decode($result->json, true);

        self::assertSame('https://example.com/p2/%package%.json', $decoded['metadata-url']);
        self::assertSame(['acme/billing', 'zeta/tools'], $decoded['available-packages']);
        // Inline v1 map still present and unchanged.
        self::assertArrayHasKey('acme/billing', $decoded['packages']);
    }

    public function testP2ManifestListsVersionsNewestFirst(): void
    {
        $entries = [
            $this->entry('acme', 'billing', '1.2.0'),
            $this->entry('acme', 'billing', '1.3.0'),
        ];
        $reader = fn (Release $e): ZipMeta => new ZipMeta(
            ['name' => "{$e->vendor}/{$e->package}", 'version' => $e->version, 'type' => 'library', 'require' => ['php' => '^8.2']],
            str_repeat('a', 40)
        );
        $result = (new PackagesJson(new NullLogger()))->build($entries, $reader, 'https://example.com');
        $manifest = json_decode($result->p2Json, true);

        self::assertArrayHasKey('acme/billing', $manifest);
        $versions = $manifest['acme/billing'];
        self::assertSame(['1.3.0', '1.2.0'], array_column($versions, 'version'));
        // Each entry is a full version object with the injected dist block.
        self::assertSame('acme/billing', $versions[0]['name']);
        self::assertSame('library', $versions[0]['type']);
        self::assertSame(
            'https://example.com/dist/acme/billing/1.3.0.zip',
            $versions[0]['dist']['url']
        );
        self::assertSame('zip', $versions[0]['dist']['type']);
        self::assertSame(str_repeat('a', 40), $versions[0]['dist']['shasum']);
    }

    public function testP2ManifestIsEmptyObjectWhenNoPackages(): void
    {
        $result = (new PackagesJson(new NullLogger()))->build([], fn (): null => null, 'https://x');
        self::assertEquals(new \stdClass(), json_decode($result->p2Json));
    }
```

- [ ] **Step 2: Run the new tests to verify they fail**

Run: `./vendor/bin/phpunit --filter 'testRootAdvertisesV2MetadataUrlAndAvailablePackages|testP2ManifestListsVersionsNewestFirst|testP2ManifestIsEmptyObjectWhenNoPackages'`
Expected: FAIL — `PackagesJsonResult::__construct()` has no `$p2Json`, and `metadata-url` / `available-packages` keys are absent.

- [ ] **Step 3: Add `p2Json` to `PackagesJsonResult`**

In `app/Catalog/PackagesJson.php`, change the `PackagesJsonResult` constructor (lines 13-19) to add `$p2Json` as the first parameter:

```php
    public function __construct(
        public string $p2Json,
        public string $json,
        public int $packagesCount,
        public int $versionsCount,
        public int $skippedCount,
    ) {
    }
```

- [ ] **Step 4: Build the v2 root keys and the p2 manifest in `build()`**

In `app/Catalog/PackagesJson.php`, replace the tail of `build()` (the block from `ksort($packages);` at line 82 through the `return new PackagesJsonResult(...)` at lines 92-97) with:

```php
        ksort($packages);
        foreach ($packages as &$versions) {
            ksort($versions);
        }
        unset($versions);

        // p2 manifest: one entry per Package, version objects newest-first.
        $p2 = [];
        foreach ($packages as $name => $versions) {
            $p2[$name] = array_values(array_reverse($versions));
        }

        // Root Index: inline v1 `packages` plus the v2 metadata-url discovery keys.
        $root = [
            'packages' => $packages === [] ? new \stdClass() : $packages,
            'metadata-url' => $baseUrl . '/p2/%package%.json',
            'available-packages' => array_keys($packages),
        ];

        $flags = JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;
        $json = json_encode($root, $flags);
        $p2Json = json_encode($p2 === [] ? new \stdClass() : $p2, $flags);

        return new PackagesJsonResult(
            p2Json: (string) $p2Json,
            json: (string) $json,
            packagesCount: count($packages),
            versionsCount: $versionCount,
            skippedCount: $skipped,
        );
```

Note: `array_keys($packages)` is already alphabetical because `$packages` was just `ksort`ed, satisfying "sorted available-packages". `%package%` survives `JSON_UNESCAPED_SLASHES` untouched.

- [ ] **Step 5: Run the full PackagesJson test file to verify all pass**

Run: `./vendor/bin/phpunit tests/Catalog/PackagesJsonTest.php`
Expected: PASS — the three new tests pass and all six pre-existing tests still pass (the inline `packages` map and counts are unchanged).

- [ ] **Step 6: Run the quality pipeline**

Run: `composer rector && composer pint && composer test && composer stan`
Expected: all green, 0 stan errors.

- [ ] **Step 7: Commit**

```bash
git add app/Catalog/PackagesJson.php tests/Catalog/PackagesJsonTest.php
git commit -m "feat(catalog): build v2 p2 manifest and metadata-url root keys"
```

---

### Task 2: Generalize `Cache` to named artifacts; write `packages.json` + `p2.json` together

Change `Cache` so a Rebuild persists a set of named artifacts atomically under one Fingerprint, and rewire `Controller` to build and cache both `packages.json` and `p2.json`. `Cache` and `Controller` change together because the `rebuild()`/`readIfFresh()`/`readIfHashMatches()` signatures change — doing them in one task keeps the build green.

**Files:**
- Modify: `app/Cache.php` (whole class)
- Modify: `app/Http/Controller.php` (`indexJson()` at lines 80-101, `rebuild()` at lines 138-169)
- Test: `tests/CacheTest.php`, `tests/Http/ControllerTest.php`

**Interfaces:**
- Produces (Cache public API, all callers updated in this task):
  - `const PACKAGES = 'packages.json';` and `const P2 = 'p2.json';`
  - `readIfFresh(string $name): ?string`
  - `readIfHashMatches(string $name, string $hash): ?string`
  - `rebuild(string $newHash, callable $build): array` where `$build` is `callable(): array<string,string>` returning an artifact-name → content map, and the return value is that same map (read back from disk on the concurrent-rebuild short-circuit).
  - `invalidate(): void` — unchanged.
- Consumes: `PackagesJsonResult` from Task 1 (`->json`, `->p2Json`, counts).

- [ ] **Step 1: Rewrite `CacheTest` for the named-artifact API (failing)**

Replace the body of every test method in `tests/CacheTest.php` that calls `rebuild`/`readIfFresh`/`readIfHashMatches` so they use the new signatures, and add a multi-artifact test. Full replacement file:

```php
<?php

declare(strict_types=1);

namespace RepAhead\Tests;

use RepAhead\Cache;
use PHPUnit\Framework\TestCase;

final class CacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/repahead-cache-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    /** @return array<string,string> */
    private function bundle(string $packages = '{"x":1}', string $p2 = '{}'): array
    {
        return [Cache::PACKAGES => $packages, Cache::P2 => $p2];
    }

    public function testReadIfFreshReturnsNullWhenCacheMissing(): void
    {
        $cache = new Cache($this->dir, ttlSeconds: 30);
        self::assertNull($cache->readIfFresh(Cache::PACKAGES));
    }

    public function testReadIfFreshReturnsNullWhenTtlIsZero(): void
    {
        $cache = new Cache($this->dir, ttlSeconds: 0);
        $cache->rebuild('hash1', fn (): array => $this->bundle());
        self::assertNull($cache->readIfFresh(Cache::PACKAGES));
    }

    public function testReadIfFreshReturnsContentWithinTtl(): void
    {
        $cache = new Cache($this->dir, ttlSeconds: 30);
        $cache->rebuild('hash1', fn (): array => $this->bundle('{"x":1}', '{"p":2}'));
        self::assertSame('{"x":1}', $cache->readIfFresh(Cache::PACKAGES));
        self::assertSame('{"p":2}', $cache->readIfFresh(Cache::P2));
    }

    public function testReadIfFreshReturnsNullWhenTtlExpired(): void
    {
        $cache = new Cache($this->dir, ttlSeconds: 1);
        $cache->rebuild('hash1', fn (): array => $this->bundle());
        touch($this->dir . '/manifest.hash', time() - 10);
        clearstatcache();
        self::assertNull($cache->readIfFresh(Cache::PACKAGES));
    }

    public function testReadIfHashMatchesReturnsContentOnMatch(): void
    {
        $cache = new Cache($this->dir, ttlSeconds: 0);
        $cache->rebuild('hash1', fn (): array => $this->bundle('{"x":1}', '{"p":2}'));
        self::assertSame('{"x":1}', $cache->readIfHashMatches(Cache::PACKAGES, 'hash1'));
        self::assertSame('{"p":2}', $cache->readIfHashMatches(Cache::P2, 'hash1'));
    }

    public function testReadIfHashMatchesReturnsNullOnMismatch(): void
    {
        $cache = new Cache($this->dir, ttlSeconds: 0);
        $cache->rebuild('hash1', fn (): array => $this->bundle());
        self::assertNull($cache->readIfHashMatches(Cache::PACKAGES, 'different-hash'));
    }

    public function testRebuildWritesAllArtifactsAtomically(): void
    {
        $cache = new Cache($this->dir, ttlSeconds: 0);
        $result = $cache->rebuild('hashA', fn (): array => $this->bundle('{"a":1}', '{"b":2}'));
        self::assertSame(['packages.json' => '{"a":1}', 'p2.json' => '{"b":2}'], $result);
        self::assertSame('{"a":1}', file_get_contents($this->dir . '/packages.json'));
        self::assertSame('{"b":2}', file_get_contents($this->dir . '/p2.json'));
        self::assertSame('hashA', file_get_contents($this->dir . '/manifest.hash'));
    }

    public function testInvalidateDropsManifestHash(): void
    {
        $cache = new Cache($this->dir, ttlSeconds: 30);
        $cache->rebuild('hashA', fn (): array => $this->bundle());
        self::assertFileExists($this->dir . '/manifest.hash');
        $cache->invalidate();
        self::assertFileDoesNotExist($this->dir . '/manifest.hash');
    }

    public function testRebuildIsLockedSerially(): void
    {
        $cache = new Cache($this->dir, ttlSeconds: 0);
        $cache->rebuild('hashA', fn (): array => $this->bundle('{"a":1}'));
        $cache->rebuild('hashB', fn (): array => $this->bundle('{"b":2}'));
        self::assertSame('{"b":2}', file_get_contents($this->dir . '/packages.json'));
        self::assertSame('hashB', file_get_contents($this->dir . '/manifest.hash'));
    }
}
```

- [ ] **Step 2: Run `CacheTest` to verify it fails**

Run: `./vendor/bin/phpunit tests/CacheTest.php`
Expected: FAIL — `Cache::PACKAGES` undefined and the new `rebuild`/`readIfFresh` signatures don't exist yet.

- [ ] **Step 3: Rewrite `Cache` for named artifacts**

Replace the whole body of `app/Cache.php` with:

```php
<?php

declare(strict_types=1);

namespace RepAhead;

use RuntimeException;

final readonly class Cache
{
    public const PACKAGES = 'packages.json';
    public const P2 = 'p2.json';

    private string $dir;
    private string $hashFile;
    private string $lockFile;

    public function __construct(string $dir, private int $ttlSeconds)
    {
        if (!is_dir($dir)) {
            throw new RuntimeException("Cache directory does not exist: $dir");
        }
        if (!is_writable($dir)) {
            throw new RuntimeException("Cache directory is not writable: $dir");
        }
        $this->dir = $dir;
        $this->hashFile = "$dir/manifest.hash";
        $this->lockFile = "$dir/.rebuild.lock";
    }

    public function readIfFresh(string $name): ?string
    {
        if ($this->ttlSeconds <= 0) {
            return null;
        }
        if (!is_file("$this->dir/$name") || !is_file($this->hashFile)) {
            return null;
        }
        clearstatcache();
        $age = time() - (int) filemtime($this->hashFile);
        if ($age >= $this->ttlSeconds) {
            return null;
        }
        return file_get_contents("$this->dir/$name") ?: null;
    }

    public function readIfHashMatches(string $name, string $hash): ?string
    {
        if (!is_file("$this->dir/$name") || !is_file($this->hashFile)) {
            return null;
        }
        $stored = trim((string) file_get_contents($this->hashFile));
        if ($stored !== $hash) {
            return null;
        }
        @touch($this->hashFile);
        return file_get_contents("$this->dir/$name") ?: null;
    }

    /**
     * @param callable(): array<string,string> $build  artifact-name => content
     * @return array<string,string>
     */
    public function rebuild(string $newHash, callable $build): array
    {
        $lock = fopen($this->lockFile, 'cb');
        if ($lock === false) {
            throw new RuntimeException("Failed to open lock: {$this->lockFile}");
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Failed to acquire rebuild lock');
            }

            // Re-check inside the lock — another process may have rebuilt.
            if (is_file($this->hashFile)) {
                $stored = trim((string) file_get_contents($this->hashFile));
                if ($stored === $newHash) {
                    $existing = $this->readAll();
                    if ($existing !== null) {
                        @touch($this->hashFile);
                        return $existing;
                    }
                }
            }

            $artifacts = $build();
            foreach ($artifacts as $name => $content) {
                $this->atomicWrite("$this->dir/$name", $content);
            }
            $this->atomicWrite($this->hashFile, $newHash);
            return $artifacts;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function invalidate(): void
    {
        @unlink($this->hashFile);
    }

    /** @return array<string,string>|null null if any known artifact is missing */
    private function readAll(): ?array
    {
        $out = [];
        foreach ([self::PACKAGES, self::P2] as $name) {
            if (!is_file("$this->dir/$name")) {
                return null;
            }
            $out[$name] = (string) file_get_contents("$this->dir/$name");
        }
        return $out;
    }

    private function atomicWrite(string $target, string $content): void
    {
        $tmp = $target . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $content) === false) {
            throw new RuntimeException("Failed to write temp file: $tmp");
        }
        if (!rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException("Failed to rename $tmp -> $target");
        }
    }
}
```

- [ ] **Step 4: Run `CacheTest` to verify it passes**

Run: `./vendor/bin/phpunit tests/CacheTest.php`
Expected: PASS.

- [ ] **Step 5: Rewire `Controller` to build and cache both artifacts**

In `app/Http/Controller.php`, replace `indexJson()` (lines 80-101) with an `ensure()` helper that both endpoints share:

```php
    /**
     * Ensure the Cache is current and return the named artifact
     * (Cache::PACKAGES or Cache::P2). Shared by packages.json, the landing
     * page, and the p2 metadata endpoint.
     *
     * @throws FilesystemException when Storage cannot be listed
     */
    private function ensure(string $name): string
    {
        $cached = $this->cache->readIfFresh($name);
        if ($cached !== null) {
            return $cached;
        }

        [$entries, $hash] = $this->catalog->scan($this->fs);

        $cached = $this->cache->readIfHashMatches($name, $hash);
        if ($cached !== null) {
            return $cached;
        }

        $artifacts = $this->cache->rebuild($hash, function () use ($entries): array {
            $result = $this->packagesJson->build(
                $entries,
                fn (Release $e): ?ZipMeta => $this->zipMetadata->read($this->fs, $e->path),
                $this->baseUrl,
            );
            return [
                Cache::PACKAGES => $result->json,
                Cache::P2 => $result->p2Json,
            ];
        });

        return $artifacts[$name];
    }

    private function indexJson(): string
    {
        return $this->ensure(Cache::PACKAGES);
    }
```

Then update `rebuild()` — replace its cache-rebuild closure (lines 151-158) so it returns both artifacts:

```php
        $result = null;
        $this->cache->rebuild($hash, function () use ($entries, &$result): array {
            $result = $this->packagesJson->build(
                $entries,
                fn (Release $e): ?ZipMeta => $this->zipMetadata->read($this->fs, $e->path),
                $this->baseUrl,
            );
            return [
                Cache::PACKAGES => $result->json,
                Cache::P2 => $result->p2Json,
            ];
        });
```

Everything else in `rebuild()` (the `$result` assertion and summary) is unchanged.

- [ ] **Step 6: Run the existing Controller and end-to-end tests to verify green**

Run: `./vendor/bin/phpunit tests/Http/ControllerTest.php tests/EndToEndTest.php tests/SmokeTest.php`
Expected: PASS — the v1 `packages.json`, landing page, `dist`, and `rebuild` behaviors are unchanged, now with `p2.json` also written to the cache dir.

- [ ] **Step 7: Run the quality pipeline**

Run: `composer rector && composer pint && composer test && composer stan`
Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add app/Cache.php app/Http/Controller.php tests/CacheTest.php
git commit -m "feat(cache): persist packages.json and p2.json as named artifacts"
```

---

### Task 3: `GET /p2/{vendor}/{package}.json` endpoint

Serve one package's v2 metadata from the cached `p2.json` manifest, with a new auth-protected route.

**Files:**
- Modify: `app/Http/Controller.php` (add `metadata()`)
- Modify: `app/App.php` (`router()` — add the route, lines 82-88 area)
- Test: `tests/Http/ControllerTest.php`, `tests/EndToEndTest.php`

**Interfaces:**
- Consumes: `Controller::ensure(Cache::P2)` from Task 2; `Cache::P2`.
- Produces: `Controller::metadata(ServerRequestInterface $request, array $args): ResponseInterface` where `$args` is `array{vendor: string, package: string}`. Route: `GET /p2/{vendor}/{package}.json`, auth-protected.

- [ ] **Step 1: Write failing Controller tests for the p2 endpoint**

Append to `tests/Http/ControllerTest.php` (the `setUp` already seeds `acme/billing/1.2.0.zip`):

```php
    public function testMetadataEndpointReturnsP2Document(): void
    {
        $resp = $this->controller->metadata(
            new ServerRequest(),
            ['vendor' => 'acme', 'package' => 'billing']
        );
        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('application/json', $resp->getHeaderLine('Content-Type'));
        $decoded = json_decode((string) $resp->getBody(), true);

        // p2 shape: packages -> name -> LIST of version objects.
        self::assertArrayHasKey('acme/billing', $decoded['packages']);
        $versions = $decoded['packages']['acme/billing'];
        self::assertSame('1.2.0', $versions[0]['version']);
        self::assertSame(
            'https://example.com/dist/acme/billing/1.2.0.zip',
            $versions[0]['dist']['url']
        );
    }

    public function testMetadataEndpointReturns404ForUnknownPackage(): void
    {
        $resp = $this->controller->metadata(
            new ServerRequest(),
            ['vendor' => 'no', 'package' => 'such']
        );
        self::assertSame(404, $resp->getStatusCode());
    }

    public function testMetadataEndpointReturns404ForDevSuffix(): void
    {
        // Composer 2 also probes name~dev.json; it must 404 (no dev versions).
        $resp = $this->controller->metadata(
            new ServerRequest(),
            ['vendor' => 'acme', 'package' => 'billing~dev']
        );
        self::assertSame(404, $resp->getStatusCode());
    }

    public function testMetadataEndpointReturns503OnStorageListingFailure(): void
    {
        $fs = new \RepAhead\Tests\Support\ThrowingFilesystem();
        $fs->failListContents();
        $controller = new Controller(
            fs: $fs,
            catalog: new Catalog(),
            zipMetadata: new ZipMetadata(),
            packagesJson: new PackagesJson(new NullLogger()),
            cache: new Cache($this->cacheDir, 0),
            baseUrl: 'https://example.com',
            logger: new NullLogger(),
        );
        $resp = $controller->metadata(
            new ServerRequest(),
            ['vendor' => 'acme', 'package' => 'billing']
        );
        self::assertSame(503, $resp->getStatusCode());
        self::assertSame(
            ['error' => 'storage_unavailable'],
            json_decode((string) $resp->getBody(), true)
        );
    }
```

- [ ] **Step 2: Run the new tests to verify they fail**

Run: `./vendor/bin/phpunit --filter 'testMetadataEndpoint'`
Expected: FAIL — `Controller::metadata()` does not exist.

- [ ] **Step 3: Implement `Controller::metadata()`**

In `app/Http/Controller.php`, add this method (place it after `packages()`):

```php
    /** @param array{vendor: string, package: string} $args */
    public function metadata(ServerRequestInterface $request, array $args): ResponseInterface
    {
        try {
            $p2Json = $this->ensure(Cache::P2);
        } catch (FilesystemException $e) {
            $this->logger->error('Storage listing failed', ['error' => $e->getMessage()]);
            return $this->errorResponse(503, 'storage_unavailable');
        }

        $name = "{$args['vendor']}/{$args['package']}";
        /** @var array<string, list<array<string, mixed>>> $manifest */
        $manifest = json_decode($p2Json, true) ?: [];
        if (!isset($manifest[$name])) {
            return (new Response())->withStatus(404);
        }

        $doc = ['packages' => [$name => $manifest[$name]]];
        return $this->jsonResponse(
            200,
            (string) json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }
```

`Cache` is already imported (`use RepAhead\Cache;` at line 15). No new imports needed.

- [ ] **Step 4: Run the Controller tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Http/ControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Register the auth-protected route**

In `app/App.php`, inside `router()`, add the p2 route immediately after the `/packages.json` route registration (after line 81):

```php
        $router->get(
            '/p2/{vendor}/{package}.json',
            function (ServerRequestInterface $req, array $args) use ($controller): ResponseInterface {
                /** @var array{vendor: string, package: string} $args */
                return $controller->metadata($req, $args);
            },
        )->middleware($auth);
```

- [ ] **Step 6: Write failing end-to-end tests for the route**

Append to `tests/EndToEndTest.php`:

```php
    public function testP2Route200(): void
    {
        $router = App::router($this->config, $this->fs);
        $resp = $router->dispatch($this->authedRequest('GET', '/p2/acme/billing.json'));
        self::assertSame(200, $resp->getStatusCode());
        $decoded = json_decode((string) $resp->getBody(), true);
        self::assertSame('1.2.0', $decoded['packages']['acme/billing'][0]['version']);
    }

    public function testP2RouteRequiresAuth(): void
    {
        $router = App::router($this->config, $this->fs);
        $resp = $router->dispatch(new ServerRequest([], [], '/p2/acme/billing.json'));
        self::assertSame(401, $resp->getStatusCode());
    }

    public function testP2RouteDevSuffix404(): void
    {
        $router = App::router($this->config, $this->fs);
        $resp = $router->dispatch($this->authedRequest('GET', '/p2/acme/billing~dev.json'));
        self::assertSame(404, $resp->getStatusCode());
    }

    public function testRootAdvertisesMetadataUrl(): void
    {
        $router = App::router($this->config, $this->fs);
        $resp = $router->dispatch($this->authedRequest('GET', '/packages.json'));
        $decoded = json_decode((string) $resp->getBody(), true);
        self::assertSame('https://example.com/p2/%package%.json', $decoded['metadata-url']);
        self::assertSame(['acme/billing'], $decoded['available-packages']);
    }
```

- [ ] **Step 7: Run the end-to-end tests to verify they pass**

Run: `./vendor/bin/phpunit tests/EndToEndTest.php`
Expected: PASS — confirms routing, auth wiring, the `~dev` 404, and metadata-url discovery through the real router.

- [ ] **Step 8: Run the quality pipeline**

Run: `composer rector && composer pint && composer test && composer stan`
Expected: all green.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controller.php app/App.php tests/Http/ControllerTest.php tests/EndToEndTest.php
git commit -m "feat(http): serve GET /p2/{vendor}/{package}.json v2 metadata"
```

---

### Task 4: Documentation — glossary and README

Record the new domain terms and endpoint so the docs match the code.

**Files:**
- Modify: `CONTEXT.md`
- Modify: `README.md`

**Interfaces:** None (docs only).

- [ ] **Step 1: Add glossary terms to `CONTEXT.md`**

Append these two entries to `CONTEXT.md`:

```markdown
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
```

- [ ] **Step 2: Document the endpoint in `README.md`**

Add a short "Endpoints" section to `README.md` (place it after the "Quick start (Docker)" section). If an endpoints list already exists, add the p2 row to it instead:

```markdown
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
```

- [ ] **Step 3: Verify docs render and are internally consistent**

Run: `grep -n "metadata-url\|p2/\|Available Packages\|Metadata URL" CONTEXT.md README.md`
Expected: the new terms and endpoint appear in both files; the endpoint path matches the route in `app/App.php` (`/p2/{vendor}/{package}.json`).

- [ ] **Step 4: Run the full quality pipeline once more**

Run: `composer rector && composer pint && composer test && composer stan`
Expected: all green (docs-only change, but confirm nothing regressed).

- [ ] **Step 5: Commit**

```bash
git add CONTEXT.md README.md
git commit -m "docs: document Composer v2 metadata-url endpoint and terms"
```

---

## Self-Review

**Spec coverage:**
- Root `metadata-url` + `available-packages` → Task 1 (Steps 3-4), verified Task 3 Step 6.
- p2 document array-of-versions shape → Task 1 (manifest) + Task 3 (`metadata()` wraps it).
- p2 built at Rebuild time, cached under one Fingerprint → Task 2 (`rebuild` writes both artifacts, hash last).
- Named-artifact Cache, atomic, no stale state → Task 2.
- `GET /p2/{vendor}/{package}.json`, auth-protected, unknown/`~dev` → 404 → Task 3.
- Storage-unavailable → 503 mirroring `packages()` → Task 3 Step 3 + test Step 1.
- v1 inline format unchanged → Task 1 keeps `packages` map; regression-checked in Task 2 Step 6.
- Tests (PackagesJson, Cache, Controller, EndToEnd/Smoke) → Tasks 1-3.
- Docs (CONTEXT.md terms, README) → Task 4. (Spec lists an optional ADR "if the cache layout change is material"; the change is contained to the Cache class and covered by CONTEXT.md's updated Cache-adjacent terms, so no separate ADR — noted here to close the gap explicitly.)

**Placeholder scan:** No TBD/TODO; every code and test step shows complete code; commands have expected output.

**Type consistency:** `PackagesJsonResult` constructor order (`p2Json, json, packagesCount, versionsCount, skippedCount`) is used consistently in Task 1 Step 3/4. `Cache::PACKAGES`/`Cache::P2`, `readIfFresh(string)`, `readIfHashMatches(string,string)`, and `rebuild(string, callable): array` are defined in Task 2 and consumed unchanged in Tasks 2-3. `Controller::metadata(ServerRequestInterface, array): ResponseInterface` and the `array{vendor,package}` arg shape match between Task 3 Step 3 (impl) and Step 5 (route).
