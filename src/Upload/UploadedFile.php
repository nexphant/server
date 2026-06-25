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
 * Represents a single uploaded file from a multipart/form-data request.
 */
class UploadedFile
{
    private static array $imageMimes = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'image/svg+xml', 'image/bmp', 'image/tiff',
    ];

    public function __construct(
        private readonly string $tmpPath,
        private readonly string $originalName,
        private readonly string $mimeType,
        private readonly int    $size,
        private readonly int    $error = UPLOAD_ERR_OK,
    ) {}

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    public function getClientOriginalName(): string { return $this->originalName; }
    public function getMimeType(): string           { return $this->mimeType; }
    public function getSize(): int                  { return $this->size; }
    public function getError(): int                 { return $this->error; }
    public function isValid(): bool                 { return $this->error === UPLOAD_ERR_OK; }
    public function getTmpPath(): string            { return $this->tmpPath; }

    public function extension(): string
    {
        return strtolower(pathinfo($this->originalName, PATHINFO_EXTENSION));
    }

    public function mimeType(): string
    {
        // Use finfo for reliable MIME detection
        if (function_exists('finfo_open') && file_exists($this->tmpPath)) {
            $fi   = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($fi, $this->tmpPath);
            finfo_close($fi);
            if ($mime !== false) {
                return $mime;
            }
        }
        return $this->mimeType;
    }

    public function size(): int { return $this->size; }

    public function isImage(): bool
    {
        return in_array($this->mimeType(), self::$imageMimes, true);
    }

    /**
     * Generate a random hashed filename preserving the original extension.
     */
    public function hashName(?string $extension = null): string
    {
        $ext = $extension ?? $this->extension();
        $hash = bin2hex(random_bytes(16));
        return $ext !== '' ? "{$hash}.{$ext}" : $hash;
    }

    // -------------------------------------------------------------------------
    // Storage
    // -------------------------------------------------------------------------

    /**
     * Move the uploaded file to a destination directory.
     * Returns the final path.
     */
    public function move(string $directory, ?string $filename = null): string
    {
        $this->assertValid();

        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new \RuntimeException("Cannot create directory: {$directory}");
        }

        $filename ??= $this->hashName();
        $dest = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

        if (!rename($this->tmpPath, $dest) && !copy($this->tmpPath, $dest)) {
            throw new \RuntimeException("Failed to move uploaded file to: {$dest}");
        }

        return $dest;
    }

    /**
     * Store under a given base path, optionally using a sub-directory.
     * Returns the stored path relative to $basePath.
     */
    public function store(string $basePath, string $subDir = ''): string
    {
        $this->assertValid();
        $dir  = $subDir !== ''
            ? rtrim($basePath, '/') . '/' . trim($subDir, '/')
            : $basePath;
        $name = $this->hashName();
        $this->move($dir, $name);
        return ($subDir !== '' ? trim($subDir, '/') . '/' : '') . $name;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function assertValid(): void
    {
        if (!$this->isValid()) {
            throw new \RuntimeException('Upload error code: ' . $this->error);
        }
        if (!file_exists($this->tmpPath)) {
            throw new \RuntimeException('Uploaded temp file does not exist');
        }
    }
}
