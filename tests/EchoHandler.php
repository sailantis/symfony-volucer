<?php

declare(strict_types=1);

namespace Sailantis\SymfonyVolucer\Tests;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A handler that echoes the request back, so a test can assert what the bridge built.
 */
final class EchoHandler
{
    public static function response(Request $request): Response
    {
        return new JsonResponse([
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'query' => $request->query->all(),
            'post' => $request->request->all(),
            'cookies' => $request->cookies->all(),
            'content_type' => $request->headers->get('Content-Type'),
            'client_ip' => $request->getClientIp(),
            'scheme' => $request->getScheme(),
            'host' => $request->getHost(),
            'port' => $request->getPort(),
            'body' => $request->getContent(),
            'server_name' => $request->server->get('SERVER_NAME'),
            'remote_addr' => $request->server->get('REMOTE_ADDR'),
        ]);
    }
}
