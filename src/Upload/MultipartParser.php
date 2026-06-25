<?php

/**
 * This file is part of the nexphant Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexphant\Server\Upload;

/**
 * Parses multipart/form-data request bodies.
 *
 * Returns an array of ['fields' => [...], 'files' => [...UploadedFile...]]
 */
class MultipartParser
{
    /**
     * @return array{fields: array<string,string>, files: array<string,UploadedFile>}
     */
    public static function parse(string $body, string $boundary): array
    {
        $result = ['fields' => [], 'files' => []];

        if ($boundary === '') {
            return $result;
        }

        $delimiter  = '--' . $boundary;
        $parts      = explode($delimiter, $body);

        // Skip preamble and epilogue
        array_shift($parts);
        array_pop($parts);

        foreach ($parts as $part) {
            // Split headers from body at double CRLF
            $split = strpos($part, "\r\n\r\n");
            if ($split === false) {
                continue;
            }

            $rawHeaders = substr($part, 2, $split - 2); // skip leading \r\n
            $data       = substr($part, $split + 4, -2); // strip trailing \r\n

            $headers = self::parsePartHeaders($rawHeaders);
            $cd      = $headers['content-disposition'] ?? '';

            $name     = self::extractParam($cd, 'name');
            $filename = self::extractParam($cd, 'filename');

            if ($name === null) {
                continue;
            }

            if ($filename !== null) {
                // File field — write to temp file
                $tmp = tempnam(sys_get_temp_dir(), 'nx_up_');
                file_put_contents($tmp, $data);
                $mime = $headers['content-type'] ?? 'application/octet-stream';
                $result['files'][$name] = new UploadedFile(
                    tmpPath:      $tmp,
                    originalName: $filename,
                    mimeType:     $mime,
                    size:         strlen($data),
                    error:        UPLOAD_ERR_OK,
                );
            } else {
                $result['fields'][$name] = $data;
            }
        }

        return $result;
    }

    /**
     * Extract boundary value from a Content-Type header.
     * e.g. "multipart/form-data; boundary=----WebKitFormBoundary..."
     */
    public static function extractBoundary(string $contentType): string
    {
        if (preg_match('/boundary=([^\s;]+)/i', $contentType, $m)) {
            return trim($m[1], '"');
        }
        return '';
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /** @return array<string,string> */
    private static function parsePartHeaders(string $raw): array
    {
        $headers = [];
        foreach (explode("\r\n", $raw) as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) continue;
            $name  = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));
            $headers[$name] = $value;
        }
        return $headers;
    }

    private static function extractParam(string $header, string $param): ?string
    {
        if (preg_match('/' . preg_quote($param, '/') . '="?([^";]*)"?/i', $header, $m)) {
            return $m[1];
        }
        return null;
    }
}
