<?php

declare(strict_types=1);

namespace RepAhead\Tests;

use RepAhead\App;
use RepAhead\Config;
use RepAhead\Tests\Support\ZipBuilder;
use Laminas\Diactoros\ServerRequest;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;

final class EndToEndTest extends TestCase
{
    private string $cacheDir;
    private Config $config;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/repahead-e2e-' . bin2hex(random_bytes(4));
        mkdir($this->cacheDir);

        $this->config = new Config([
            'APP_BASE_URL' => 'https://example.com',
            'STORAGE_DSN' => 'local:/unused-by-this-test',
            'CACHE_DIR' => $this->cacheDir,
            'LISTING_TTL_SECONDS' => '0',
            'AUTH_USER' => 'ci',
            'AUTH_PASS' => 'secret',
        ]);

        $this->fs = new Filesystem(new InMemoryFilesystemAdapter());
        $this->fs->write(
            'acme/billing/1.2.0.zip',
            ZipBuilder::buildBytes(['name' => 'acme/billing', 'version' => '1.2.0', 'type' => 'library'])
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cacheDir);
    }

    private function authedRequest(string $method, string $uri): ServerRequest
    {
        $r = new ServerRequest([], [], $uri, $method);
        return $r->withHeader('Authorization', 'Basic ' . base64_encode('ci:secret'));
    }

    public function testHealthRouteRequiresNoAuth(): void
    {
        $router = App::router($this->config, $this->fs);
        $resp = $router->dispatch(new ServerRequest([], [], '/health', 'GET'));
        self::assertSame(200, $resp->getStatusCode());
        self::assertSame(['status' => 'ok'], json_decode((string) $resp->getBody(), true));
    }

    public function testPackagesRoute200(): void
    {
        $router = App::router($this->config, $this->fs);
        $resp = $router->dispatch($this->authedRequest('GET', '/packages.json'));
        self::assertSame(200, $resp->getStatusCode());
        $decoded = json_decode((string) $resp->getBody(), true);
        self::assertArrayHasKey('acme/billing', $decoded['packages']);
    }

    public function testPackagesRouteRequiresAuth(): void
    {
        $router = App::router($this->config, $this->fs);
        $resp = $router->dispatch(new ServerRequest([], [], '/packages.json'));
        self::assertSame(401, $resp->getStatusCode());
    }

    public function testDistRoute200(): void
    {
        $router = App::router($this->config, $this->fs);
        $resp = $router->dispatch($this->authedRequest('GET', '/dist/acme/billing/1.2.0.zip'));
        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('application/zip', $resp->getHeaderLine('Content-Type'));
    }

    public function testDistRoute404(): void
    {
        $router = App::router($this->config, $this->fs);
        $resp = $router->dispatch($this->authedRequest('GET', '/dist/no/such/0.0.0.zip'));
        self::assertSame(404, $resp->getStatusCode());
    }

    public function testRebuildRoute200(): void
    {
        $router = App::router($this->config, $this->fs);
        $resp = $router->dispatch($this->authedRequest('POST', '/rebuild'));
        self::assertSame(200, $resp->getStatusCode());
        $decoded = json_decode((string) $resp->getBody(), true);
        self::assertSame(1, $decoded['packages']);
    }

    public function testUnknownRoute404(): void
    {
        $router = App::router($this->config, $this->fs);
        $resp = $router->dispatch($this->authedRequest('GET', '/anything-else'));
        self::assertSame(404, $resp->getStatusCode());
    }

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

    public function testSafeJsonStrategySanitisesUncaughtException(): void
    {
        $router = new \League\Route\Router();
        $router->setStrategy(new \RepAhead\Http\SafeJsonStrategy(new \Laminas\Diactoros\ResponseFactory()));
        $router->get('/boom', function (): \Psr\Http\Message\ResponseInterface {
            throw new \RuntimeException('Cache directory does not exist: /var/private/leak');
        });

        $resp = App::safeDispatch($router, new \Laminas\Diactoros\ServerRequest([], [], '/boom', 'GET'));

        self::assertSame(500, $resp->getStatusCode());
        self::assertSame('application/json', $resp->getHeaderLine('Content-Type'));
        self::assertSame(
            ['error' => 'internal_server_error'],
            json_decode((string) $resp->getBody(), true)
        );
        // Critically: the leaked path must NOT appear in the response body or reason phrase.
        self::assertStringNotContainsString('/var/private/leak', (string) $resp->getBody());
        self::assertStringNotContainsString('/var/private/leak', $resp->getReasonPhrase());
    }
}
