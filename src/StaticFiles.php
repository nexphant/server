<?php

/**
 * This file is part of the Nexph Framework.
 *
 * (c) Nexphlabs <https://github.com/nexphlabs>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexph\Server;

class StaticFiles {
    private string $root;
    private array $mimeTypes;
    private int $maxAge;
    private bool $etag;

    public function __construct(string $root, array $options = []) {
        $this->root = rtrim($root, '/');
        $this->maxAge = $options['max_age'] ?? 86400;
        $this->etag = $options['etag'] ?? true;
        $this->mimeTypes = array_merge([
            'html' => 'text/html',
            'htm' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'txt' => 'text/plain',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'webp' => 'image/webp',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject',
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
        ], $options['mime_types'] ?? []);
    }

    public function __invoke(ServerRequest $request, ServerResponse $response): \Generator {
        if ($request->method !== 'GET' && $request->method !== 'HEAD') {
            return;
        }

        $path = urldecode($request->path);

        // Security: prevent directory traversal
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            return;
        }

        $file = $this->root . $path;

        // Try index.html for directories
        if (is_dir($file)) {
            $file = rtrim($file, '/') . '/index.html';
        }

        if (!is_file($file) || !is_readable($file)) {
            return;
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = $this->mimeTypes[$ext] ?? 'application/octet-stream';
        $size = filesize($file);
        $mtime = filemtime($file);

        // ETag
        if ($this->etag) {
            $etag = '"' . md5($file . $mtime . $size) . '"';
            $response->header('ETag', $etag);

            $ifNoneMatch = $request->header('if-none-match');
            if ($ifNoneMatch === $etag) {
                $response->status(304)->body('');
                $response->markSent();
                return;
            }
        }

        // Last-Modified
        $response->header('Last-Modified', gmdate('D, d M Y H:i:s T', $mtime));

        $ifModifiedSince = $request->header('if-modified-since');
        if ($ifModifiedSince && strtotime($ifModifiedSince) >= $mtime) {
            $response->status(304)->body('');
            $response->markSent();
            return;
        }

        // Cache headers
        $response->header('Cache-Control', "public, max-age={$this->maxAge}");
        $response->header('Content-Type', $mime);

        // Read file async
        $content = yield from AsyncIO::readFile($file);
        $response->body($content ?? '');
        $response->markSent();
    }

    public function serve(string $path): \Generator {
        $file = $this->root . '/' . ltrim($path, '/');

        if (!is_file($file)) {
            return null;
        }

        return yield from AsyncIO::readFile($file);
    }
}
