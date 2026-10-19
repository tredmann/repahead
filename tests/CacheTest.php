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

    public function testRebuildRunsRealBuildWhenAnArtifactIsMissingDespiteHashMatch(): void
    {
        $cache = new Cache($this->dir, ttlSeconds: 0);
        $cache->rebuild('hashA', fn (): array => $this->bundle('{"a":1}', '{"b":2}'));

        // Simulate a lost artifact while the fingerprint still matches.
        unlink($this->dir . '/p2.json');

        $built = false;
        $result = $cache->rebuild('hashA', function () use (&$built): array {
            $built = true;
            return $this->bundle('{"a":1}', '{"b":2}');
        });

        self::assertTrue($built, 'build closure must run when an artifact is missing');
        self::assertSame('{"b":2}', file_get_contents($this->dir . '/p2.json'));
        self::assertSame(['packages.json' => '{"a":1}', 'p2.json' => '{"b":2}'], $result);
    }
}
