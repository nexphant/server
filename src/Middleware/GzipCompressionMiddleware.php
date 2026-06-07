<?php

namespace Nexph\Server\Middleware;

class GzipCompressionMiddleware
{
    private int $threshold;
    private array $contentTypes;

    public function __construct(int $threshold = 1024, array $contentTypes = ['application/json', 'text/html', 'text/plain', 'text/css', 'application/javascript'])
    {
        $this->threshold = $threshold;
        $this->contentTypes = $contentTypes;
    }

    public function __invoke($request, callable $next)
    {
        $response = $next($request);

        if (!extension_loaded('zlib')) {
            return $response;
        }

        $acceptEncoding = $request->header('Accept-Encoding') ?? '';
        if (stripos($acceptEncoding, 'gzip') === false) {
            return $response;
        }

        $contentType = $response->getHeader('Content-Type') ?? '';
        $shouldCompress = false;
        foreach ($this->contentTypes as $type) {
            if (stripos($contentType, $type) !== false) {
                $shouldCompress = true;
                break;
            }
        }

        if (!$shouldCompress) {
            return $response;
        }

        $body = $response->getBody();
        if (strlen($body) < $this->threshold) {
            return $response;
        }

        $compressed = gzencode($body, 6);
        if ($compressed === false) {
            return $response;
        }

        $response->setHeader('Content-Encoding', 'gzip');
        $response->setHeader('Content-Length', (string) strlen($compressed));
        $response->setBody($compressed);
        $response->setHeader('Vary', 'Accept-Encoding');

        return $response;
    }
}
