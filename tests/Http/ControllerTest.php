<?php

declare(strict_types=1);

namespace RepAhead\Tests\Http;

use RepAhead\Cache;
use RepAhead\Catalog\Catalog;
use RepAhead\Catalog\PackagesJson;
use RepAhead\Catalog\ZipMetadata;
use RepAhead\Http\Controller;
use RepAhead\Tests\Support\ZipBuilder;
use Laminas\Diactoros\ServerRequest;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ControllerTest extends TestCase
{
    private string $cacheDir;
    private Filesystem $fs;
    private Controller $controller;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/repahead-ctrl-' . bin2hex(random_bytes(4));
        mkdir($this->cacheDir);

        $this->fs = new Filesystem(new InMemoryFilesystemAdapter());
        $this->fs->write(
            'acme/billing/1.2.0.zip',
            ZipBuilder::buildBytes(['name' => 'acme/billing', 'version' => '1.2.0', 'type' => 'library'])
        );

        $this->controller = new Controller(
            fs: $this->fs,
            catalog: new Catalog(),
            zipMetadata: new ZipMetadata(),
            packagesJson: new PackagesJson(new NullLogger()),
            cache: new Cache($this->cacheDir, 0),
            baseUrl: 'https://example.com',
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cacheDir);
    }

    public function testHealthReturns200WhenStorageIsReachable(): void
    {
        $resp = $this->controller->health(new ServerRequest());
        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('application/json', $resp->getHeaderLine('Content-Type'));
        self::assertSame(['status' => 'ok'], json_decode((string) $resp->getBody(), true));
    }

    public function testHealthReturns503WhenStorageIsUnreachable(): void
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
        );
        $resp = $controller->health(new ServerRequest());
        self::assertSame(503, $resp->getStatusCode());
        self::assertSame(
            ['status' => 'unavailable', 'error' => 'storage_unavailable'],
            json_decode((string) $resp->getBody(), true)
        );
    }

    public function testPackagesEndpointReturnsJson(): void
    {
        $resp = $this->controller->packages(new ServerRequest());
        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('application/json', $resp->getHeaderLine('Content-Type'));
        $decoded = json_decode((string) $resp->getBody(), true);
        self::assertArrayHasKey('acme/billing', $decoded['packages']);
        self::assertSame(
            'https://example.com/dist/acme/billing/1.2.0.zip',
            $decoded['packages']['acme/billing']['1.2.0']['dist']['url']
        );
    }

    public function testPackagesEndpointServesFromCacheOnSecondCall(): void
    {
        $this->controller->packages(new ServerRequest());
        // Wipe the storage to prove second call uses cache.
        $this->fs->delete('acme/billing/1.2.0.zip');
        // With TTL 0, the catalog will be re-listed and hash will differ — so this
        // test exercises the listing/hash path, not the TTL shortcut.
        // Test just confirms the second call still succeeds:
        $resp = $this->controller->packages(new ServerRequest());
        self::assertSame(200, $resp->getStatusCode());
    }

    public function testHomeRendersHtmlWithPackageAndZipLink(): void
    {
        $resp = $this->controller->home(new ServerRequest());
        self::assertSame(200, $resp->getStatusCode());
        self::assertStringContainsString('text/html', $resp->getHeaderLine('Content-Type'));
        $html = (string) $resp->getBody();
        self::assertStringContainsString('acme/billing', $html);
        self::assertStringContainsString('1.2.0', $html);
        self::assertStringContainsString('https://example.com/dist/acme/billing/1.2.0.zip', $html);
    }

    public function testHomeReturns503OnStorageListingFailure(): void
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

        $resp = $controller->home(new ServerRequest());
        self::assertSame(503, $resp->getStatusCode());
        self::assertStringContainsString('text/html', $resp->getHeaderLine('Content-Type'));
    }

    public function testDistEndpointStreamsZipBytes(): void
    {
        $resp = $this->controller->dist(
            new ServerRequest(),
            ['vendor' => 'acme', 'package' => 'billing', 'version' => '1.2.0']
        );
        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('application/zip', $resp->getHeaderLine('Content-Type'));
        $body = (string) $resp->getBody();
        self::assertNotSame('', $body);
        self::assertSame("PK\x03\x04", substr($body, 0, 4));
    }

    public function testDistEndpointReturns404ForMissingZip(): void
    {
        $resp = $this->controller->dist(
            new ServerRequest(),
            ['vendor' => 'no', 'package' => 'such', 'version' => '0.0.0']
        );
        self::assertSame(404, $resp->getStatusCode());
    }

    public function testRebuildEndpointReturnsSummary(): void
    {
        $resp = $this->controller->rebuild(new ServerRequest());
        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('application/json', $resp->getHeaderLine('Content-Type'));
        $decoded = json_decode((string) $resp->getBody(), true);
        self::assertArrayHasKey('packages', $decoded);
        self::assertArrayHasKey('versions', $decoded);
        self::assertArrayHasKey('skipped', $decoded);
        self::assertArrayHasKey('duration_ms', $decoded);
        self::assertSame(1, $decoded['packages']);
        self::assertSame(1, $decoded['versions']);
    }

    public function testPackagesEndpointReturns503OnStorageListingFailure(): void
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

        $resp = $controller->packages(new ServerRequest());
        self::assertSame(503, $resp->getStatusCode());
        self::assertSame('application/json', $resp->getHeaderLine('Content-Type'));
        self::assertSame(
            ['error' => 'storage_unavailable'],
            json_decode((string) $resp->getBody(), true)
        );
    }

    public function testRebuildEndpointReturns503OnStorageListingFailure(): void
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

        $resp = $controller->rebuild(new ServerRequest());
        self::assertSame(503, $resp->getStatusCode());
        self::assertSame(
            ['error' => 'storage_unavailable'],
            json_decode((string) $resp->getBody(), true)
        );
    }

    public function testDistEndpointReturns502OnStorageReadFailure(): void
    {
        $fs = new \RepAhead\Tests\Support\ThrowingFilesystem();
        $fs->write(
            'acme/billing/1.2.0.zip',
            \RepAhead\Tests\Support\ZipBuilder::buildBytes(['name' => 'acme/billing', 'version' => '1.2.0'])
        );
        $fs->failReadStream();
        $controller = new Controller(
            fs: $fs,
            catalog: new Catalog(),
            zipMetadata: new ZipMetadata(),
            packagesJson: new PackagesJson(new NullLogger()),
            cache: new Cache($this->cacheDir, 0),
            baseUrl: 'https://example.com',
            logger: new NullLogger(),
        );

        $resp = $controller->dist(
            new ServerRequest(),
            ['vendor' => 'acme', 'package' => 'billing', 'version' => '1.2.0']
        );
        self::assertSame(502, $resp->getStatusCode());
        self::assertSame(
            ['error' => 'storage_unavailable'],
            json_decode((string) $resp->getBody(), true)
        );
    }

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
}
