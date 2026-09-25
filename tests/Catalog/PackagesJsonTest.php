<?php

declare(strict_types=1);

namespace RepAhead\Tests\Catalog;

use RepAhead\Catalog\Release;
use RepAhead\Catalog\PackagesJson;
use RepAhead\Catalog\ZipMeta;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PackagesJsonTest extends TestCase
{
    private function entry(string $vendor, string $package, string $version): Release
    {
        return new Release(
            $vendor,
            $package,
            $version,
            "$vendor/$package/$version.zip",
            100,
            1700000000
        );
    }

    public function testBuildsExpectedShape(): void
    {
        $entries = [
            $this->entry('acme', 'billing', '1.2.0'),
            $this->entry('acme', 'billing', '1.3.0'),
        ];
        $reader = function (Release $e): ZipMeta {
            return new ZipMeta(
                ['name' => "{$e->vendor}/{$e->package}", 'version' => $e->version, 'type' => 'library', 'require' => ['php' => '^8.2']],
                str_repeat('a', 40)
            );
        };
        $builder = new PackagesJson(new NullLogger());

        $result = $builder->build($entries, $reader, 'https://example.com');
        $decoded = json_decode($result->json, true);

        self::assertArrayHasKey('packages', $decoded);
        self::assertArrayHasKey('acme/billing', $decoded['packages']);
        self::assertSame(
            ['1.2.0', '1.3.0'],
            array_keys($decoded['packages']['acme/billing'])
        );
        $v = $decoded['packages']['acme/billing']['1.2.0'];
        self::assertSame('acme/billing', $v['name']);
        self::assertSame('1.2.0', $v['version']);
        self::assertSame('library', $v['type']);
        self::assertSame(['php' => '^8.2'], $v['require']);
        self::assertSame(
            'https://example.com/dist/acme/billing/1.2.0.zip',
            $v['dist']['url']
        );
        self::assertSame('zip', $v['dist']['type']);
        self::assertSame(str_repeat('a', 40), $v['dist']['reference']);
        self::assertSame(str_repeat('a', 40), $v['dist']['shasum']);
        self::assertSame(1, $result->packagesCount);
        self::assertSame(2, $result->versionsCount);
        self::assertSame(0, $result->skippedCount);
    }

    public function testSkipsEntriesWhenMetadataReaderReturnsNull(): void
    {
        $entries = [
            $this->entry('a', 'b', '1.0.0'),
            $this->entry('a', 'b', '2.0.0'),
        ];
        $reader = fn (Release $e): ?ZipMeta => $e->version === '2.0.0'
            ? null
            : new ZipMeta(['name' => 'a/b', 'version' => '1.0.0'], str_repeat('b', 40));
        $builder = new PackagesJson(new NullLogger());
        $result = $builder->build($entries, $reader, 'https://x');
        $decoded = json_decode($result->json, true);

        self::assertSame(['1.0.0'], array_keys($decoded['packages']['a/b']));
        self::assertSame(1, $result->skippedCount);
    }

    public function testSkipsWhenComposerJsonNameMismatchesPath(): void
    {
        $entries = [$this->entry('a', 'b', '1.0.0')];
        $reader = fn (): ZipMeta => new ZipMeta(['name' => 'wrong/name', 'version' => '1.0.0'], str_repeat('c', 40));
        $builder = new PackagesJson(new NullLogger());
        $result = $builder->build($entries, $reader, 'https://x');
        $decoded = json_decode($result->json, true);

        self::assertSame([], $decoded['packages'] ?? []);
        self::assertSame(1, $result->skippedCount);
    }

    public function testFilenameVersionWinsButLogsOnMismatch(): void
    {
        $entries = [$this->entry('a', 'b', '1.0.0')];
        $reader = fn (): ZipMeta => new ZipMeta(
            ['name' => 'a/b', 'version' => '9.9.9', 'type' => 'library'],
            str_repeat('d', 40)
        );
        $builder = new PackagesJson(new NullLogger());
        $result = $builder->build($entries, $reader, 'https://x');
        $decoded = json_decode($result->json, true);

        self::assertSame('1.0.0', $decoded['packages']['a/b']['1.0.0']['version']);
        self::assertSame(0, $result->skippedCount);
    }

    public function testSkipsWhenComposerJsonMissingName(): void
    {
        $entries = [$this->entry('a', 'b', '1.0.0')];
        $reader = fn (): ZipMeta => new ZipMeta(['version' => '1.0.0'], str_repeat('e', 40));
        $builder = new PackagesJson(new NullLogger());
        $result = $builder->build($entries, $reader, 'https://x');
        self::assertSame(1, $result->skippedCount);
    }

    public function testEmptyEntriesProducesEmptyPackages(): void
    {
        $builder = new PackagesJson(new NullLogger());
        $result = $builder->build([], fn (): null => null, 'https://x');
        $decoded = json_decode($result->json, true);
        $expectedObj = new \stdClass();
        $expectedObj->packages = new \stdClass();
        self::assertEquals($expectedObj, json_decode($result->json), 'should be {} not []');
        self::assertSame([], $decoded['packages']);
        self::assertSame(0, $result->packagesCount);
    }
}
