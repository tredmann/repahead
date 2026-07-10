<?php

declare(strict_types=1);

namespace RepAhead\Http;

use Laminas\Diactoros\Response;
use Laminas\Diactoros\Stream;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RepAhead\Cache;
use RepAhead\Catalog\Catalog;
use RepAhead\Catalog\Release;
use RepAhead\Catalog\PackagesJson;
use RepAhead\Catalog\PackagesJsonResult;
use RepAhead\Catalog\ZipMeta;
use RepAhead\Catalog\ZipMetadata;

final readonly class Controller
{
    public function __construct(
        private Filesystem $fs,
        private Catalog $catalog,
        private ZipMetadata $zipMetadata,
        private PackagesJson $packagesJson,
        private Cache $cache,
        private string $baseUrl,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function health(ServerRequestInterface $request): ResponseInterface
    {
        try {
            foreach ($this->fs->listContents('', false) as $_) {
                break;
            }
            return $this->jsonResponse(200, '{"status":"ok"}');
        } catch (FilesystemException $e) {
            $this->logger->error('Health check: storage unavailable', ['error' => $e->getMessage()]);
            return $this->jsonResponse(503, '{"status":"unavailable","error":"storage_unavailable"}');
        }
    }

    public function packages(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return $this->jsonResponse(200, $this->indexJson());
        } catch (FilesystemException $e) {
            $this->logger->error('Storage listing failed', ['error' => $e->getMessage()]);
            return $this->errorResponse(503, 'storage_unavailable');
        }
    }

    public function home(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $json = $this->indexJson();
        } catch (FilesystemException $e) {
            $this->logger->error('Storage listing failed', ['error' => $e->getMessage()]);
            return $this->htmlResponse(503, '<!DOCTYPE html><title>503</title><p>Storage unavailable.</p>');
        }

        /** @var array{packages?: array<string, array<string, array<string, mixed>>>} $data */
        $data = json_decode($json, true);

        return $this->htmlResponse(200, IndexView::render($data['packages'] ?? [], $this->baseUrl));
    }

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

    /** @param array{vendor: string, package: string, version: string} $args */
    public function dist(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $path = "{$args['vendor']}/{$args['package']}/{$args['version']}.zip";

        try {
            $exists = $this->fs->fileExists($path);
        } catch (FilesystemException $e) {
            $this->logger->error('Failed to check ZIP existence', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse(502, 'storage_unavailable');
        }
        if (!$exists) {
            return (new Response())->withStatus(404);
        }

        try {
            $stream = $this->fs->readStream($path);
        } catch (FilesystemException $e) {
            $this->logger->error('Failed to stream ZIP', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse(502, 'storage_unavailable');
        }

        $body = new Stream($stream);
        return (new Response($body))
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="' . basename($path) . '"');
    }

    public function rebuild(ServerRequestInterface $request): ResponseInterface
    {
        $start = microtime(true);
        $this->cache->invalidate();

        try {
            [$entries, $hash] = $this->catalog->scan($this->fs);
        } catch (FilesystemException $e) {
            $this->logger->error('Storage listing failed during rebuild', ['error' => $e->getMessage()]);
            return $this->errorResponse(503, 'storage_unavailable');
        }

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

        // invalidate() above guarantees Cache::rebuild calls the closure, so $result is set.
        \assert($result instanceof PackagesJsonResult);
        $summary = [
            'packages' => $result->packagesCount,
            'versions' => $result->versionsCount,
            'skipped' => $result->skippedCount,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
        ];
        return $this->jsonResponse(200, (string) json_encode($summary, JSON_UNESCAPED_SLASHES));
    }

    private function jsonResponse(int $status, string $body): ResponseInterface
    {
        $resp = new Response();
        $resp->getBody()->write($body);
        return $resp
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }

    private function htmlResponse(int $status, string $body): ResponseInterface
    {
        $resp = new Response();
        $resp->getBody()->write($body);
        return $resp
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function errorResponse(int $status, string $errorCode): ResponseInterface
    {
        return $this->jsonResponse(
            $status,
            (string) json_encode(['error' => $errorCode], JSON_UNESCAPED_SLASHES)
        );
    }
}
