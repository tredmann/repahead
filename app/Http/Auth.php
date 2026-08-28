<?php

declare(strict_types=1);

namespace RepAhead\Http;

use Laminas\Diactoros\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class Auth implements MiddlewareInterface
{
    public function __construct(
        private string $user,
        private string $pass,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');
        if (!str_starts_with($header, 'Basic ')) {
            $this->logger->warning('Auth rejected: missing or non-Basic Authorization header');
            return $this->unauthorized();
        }
        $decoded = base64_decode(substr($header, 6), true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            $this->logger->warning('Auth rejected: malformed Basic credentials (bad base64 or missing colon)');
            return $this->unauthorized();
        }
        [$user, $pass] = explode(':', $decoded, 2);

        $userOk = hash_equals($this->user, $user);
        $passOk = hash_equals($this->pass, $pass);
        if (!$userOk || !$passOk) {
            $this->logger->warning('Auth rejected: invalid credentials', [
                'submitted_user' => $user,
                'user_match' => $userOk,
                'pass_match' => $passOk,
            ]);
            return $this->unauthorized();
        }

        return $handler->handle($request);
    }

    private function unauthorized(): ResponseInterface
    {
        return (new Response())
            ->withStatus(401)
            ->withHeader('WWW-Authenticate', 'Basic realm="composer"');
    }
}
