<?php

declare(strict_types=1);

namespace RepAhead;

use Laminas\Diactoros\Response;
use Laminas\Diactoros\ResponseFactory;
use League\Flysystem\Filesystem;
use League\Route\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RepAhead\Catalog\Catalog;
use RepAhead\Catalog\PackagesJson;
use RepAhead\Catalog\ZipMetadata;
use RepAhead\Http\Auth;
use RepAhead\Http\Controller;
use RepAhead\Http\SafeJsonStrategy;
use Throwable;

final class App
{
    /**
     * Dispatch the request and translate any uncaught exception into a generic
     * 500 with a fixed JSON body. League Route's JsonStrategy default would
     * otherwise echo the exception message (including absolute file paths)
     * to the client.
     */
    public static function safeDispatch(
        Router $router,
        ServerRequestInterface $request,
        LoggerInterface $logger = new NullLogger(),
    ): ResponseInterface {
        try {
            return $router->dispatch($request);
        } catch (Throwable $e) {
            $logger->error('Uncaught exception in dispatch', [
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);
            $resp = new Response();
            $resp->getBody()->write((string) json_encode(
                ['error' => 'internal_server_error'],
                JSON_UNESCAPED_SLASHES
            ));
            return $resp
                ->withStatus(500)
                ->withHeader('Content-Type', 'application/json');
        }
    }

    public static function router(Config $config, Filesystem $fs, LoggerInterface $logger = new NullLogger()): Router
    {

        $controller = new Controller(
            fs: $fs,
            catalog: new Catalog(),
            zipMetadata: new ZipMetadata($logger),
            packagesJson: new PackagesJson($logger),
            cache: new Cache($config->cacheDir(), $config->listingTtlSeconds()),
            baseUrl: $config->baseUrl(),
            logger: $logger,
        );

        $auth = new Auth($config->authUser(), $config->authPass(), $logger);

        // SafeJsonStrategy handles 404/405 as JSON responses and sanitises uncaught exceptions.
        $strategy = new SafeJsonStrategy(new ResponseFactory(), $logger);
        $router = new Router();
        $router->setStrategy($strategy);

        // Public — no auth required.
        $router->get('/health', fn (ServerRequestInterface $req): ResponseInterface => $controller->health($req));

        // Protected — auth required per route.
        $router->get('/', fn (ServerRequestInterface $req): ResponseInterface => $controller->home($req))
            ->middleware($auth);
        $router->get('/packages.json', fn (ServerRequestInterface $req): ResponseInterface => $controller->packages($req))
            ->middleware($auth);
        $router->get(
            '/p2/{vendor}/{package}.json',
            function (ServerRequestInterface $req, array $args) use ($controller): ResponseInterface {
                /** @var array{vendor: string, package: string} $args */
                return $controller->metadata($req, $args);
            },
        )->middleware($auth);
        $router->get(
            '/dist/{vendor}/{package}/{version}.zip',
            function (ServerRequestInterface $req, array $args) use ($controller): ResponseInterface {
                /** @var array{vendor: string, package: string, version: string} $args */
                return $controller->dist($req, $args);
            },
        )->middleware($auth);
        $router->post('/rebuild', fn (ServerRequestInterface $req): ResponseInterface => $controller->rebuild($req))
            ->middleware($auth);

        return $router;
    }
}
