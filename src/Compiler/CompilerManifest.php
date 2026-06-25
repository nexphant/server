<?php

namespace Nexphant\Server\Compiler;

/**
 * CompilerManifest — generates storage/framework/ compiled files.
 *
 * Output files:
 *   storage/framework/routes.php
 *   storage/framework/metadata.php
 *   storage/framework/models.php
 *   storage/framework/middleware.php
 *   storage/framework/manifest.php
 */
final class CompilerManifest
{
    private string $outputDir;

    public function __construct(string $outputDir = '')
    {
        $this->outputDir = $outputDir ?: (defined('NEXPHANT_BASE_PATH')
            ? NEXPHANT_BASE_PATH . '/storage/framework'
            : getcwd() . '/storage/framework');
    }

    public function compile(array $manifest): void
    {
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }

        foreach ($manifest as $key => $data) {
            $file = $this->outputDir . '/' . $key . '.php';
            $this->writePhpFile($file, $key, $data);
        }

        // Write master manifest
        $this->writePhpFile(
            $this->outputDir . '/manifest.php',
            'manifest',
            array_merge($manifest, ['compiled_at' => date('Y-m-d H:i:s'), 'keys' => array_keys($manifest)])
        );
    }

    public function load(string $key): mixed
    {
        $file = $this->outputDir . '/' . $key . '.php';
        if (!file_exists($file)) {
            return null;
        }
        return require $file;
    }

    public function exists(): bool
    {
        return file_exists($this->outputDir . '/manifest.php');
    }

    public function flush(): void
    {
        if (!is_dir($this->outputDir)) return;
        foreach (glob($this->outputDir . '/*.php') as $f) {
            unlink($f);
        }
    }

    private function writePhpFile(string $path, string $key, mixed $data): void
    {
        $export  = var_export($data, true);
        $content = "<?php\n// Nexphant compiled — {$key} — " . date('Y-m-d H:i:s') . "\nreturn {$export};\n";
        file_put_contents($path, $content, LOCK_EX);
    }
}
