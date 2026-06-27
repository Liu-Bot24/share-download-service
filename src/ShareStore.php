<?php
declare(strict_types=1);

final class ShareStore
{
    private string $filesDir;
    private string $metadataPath;

    public function __construct(string $filesDir, string $metadataPath) {
        $this->filesDir = $filesDir;
        $this->metadataPath = $metadataPath;
        $this->ensureDir($this->filesDir);
        $this->ensureDir(dirname($this->metadataPath));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listFiles(): array
    {
        $stats = $this->readStats();
        $files = [];

        foreach (scandir($this->filesDir) ?: [] as $name) {
            if (!$this->isListableName($name)) {
                continue;
            }
            $path = $this->filesDir . '/' . $name;
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }

            $fileStats = $stats[$name] ?? [];
            $files[] = [
                'name' => $name,
                'path' => $path,
                'mime_type' => $this->mimeType($path),
                'bytes' => filesize($path),
                'mtime' => filemtime($path),
                'sha256' => hash_file('sha256', $path),
                'downloads' => (int) ($fileStats['downloads'] ?? 0),
                'last_downloaded_at' => $fileStats['last_downloaded_at'] ?? null,
            ];
        }

        usort($files, static fn (array $a, array $b): int => ($b['mtime'] <=> $a['mtime']) ?: strcmp((string) $a['name'], (string) $b['name']));
        return $files;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $name): ?array
    {
        $this->assertValidFilename($name);
        $path = $this->filesDir . '/' . $name;
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $stats = $this->readStats();
        $fileStats = $stats[$name] ?? [];

        return [
            'name' => $name,
            'path' => $path,
            'mime_type' => $this->mimeType($path),
            'bytes' => filesize($path),
            'mtime' => filemtime($path),
            'sha256' => hash_file('sha256', $path),
            'downloads' => (int) ($fileStats['downloads'] ?? 0),
            'last_downloaded_at' => $fileStats['last_downloaded_at'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function recordDownload(string $name): array
    {
        $this->assertValidFilename($name);
        $path = $this->filesDir . '/' . $name;
        if (!is_file($path) || !is_readable($path)) {
            throw new OutOfBoundsException('File not found.');
        }

        $downloaded = null;
        $this->withLockedStats(function (array &$stats) use ($name, $path, &$downloaded): void {
            $current = $stats[$name] ?? ['downloads' => 0, 'last_downloaded_at' => null];
            $current['downloads'] = ((int) $current['downloads']) + 1;
            $current['last_downloaded_at'] = gmdate('c');
            $stats[$name] = $current;

            $downloaded = [
                'name' => $name,
                'path' => $path,
                'mime_type' => $this->mimeType($path),
                'bytes' => filesize($path),
                'mtime' => filemtime($path),
                'sha256' => hash_file('sha256', $path),
                'downloads' => (int) $current['downloads'],
                'last_downloaded_at' => $current['last_downloaded_at'],
            ];
        });

        return $downloaded;
    }

    private function isListableName(string $name): bool
    {
        if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
            return false;
        }
        try {
            $this->assertValidFilename($name);
        } catch (InvalidArgumentException) {
            return false;
        }
        return true;
    }

    private function assertValidFilename(string $name): void
    {
        if ($name === '' || $name === '.' || $name === '..') {
            throw new InvalidArgumentException('Invalid filename.');
        }
        if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
            throw new InvalidArgumentException('Invalid filename.');
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readStats(): array
    {
        if (!is_file($this->metadataPath)) {
            return [];
        }
        $json = file_get_contents($this->metadataPath);
        if ($json === false || trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Stats file is invalid JSON.');
        }
        return $decoded;
    }

    /**
     * @param callable(array<string, array<string, mixed>>&): void $callback
     */
    private function withLockedStats(callable $callback): void
    {
        $this->ensureDir(dirname($this->metadataPath));
        $handle = fopen($this->metadataPath, 'c+');
        if (!$handle) {
            throw new RuntimeException('Could not open stats file.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Could not lock stats file.');
            }

            rewind($handle);
            $contents = stream_get_contents($handle);
            $stats = [];
            if ($contents !== false && trim($contents) !== '') {
                $decoded = json_decode($contents, true);
                if (!is_array($decoded)) {
                    throw new RuntimeException('Stats file is invalid JSON.');
                }
                $stats = $decoded;
            }

            $callback($stats);

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
            chmod($this->metadataPath, 0660);
        }
    }

    private function mimeType(string $path): string
    {
        if (function_exists('mime_content_type')) {
            $type = mime_content_type($path);
            if (is_string($type) && $type !== '') {
                return $type;
            }
        }
        return str_ends_with(strtolower($path), '.json') ? 'application/json' : 'application/octet-stream';
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create directory: ' . $dir);
        }
    }
}
