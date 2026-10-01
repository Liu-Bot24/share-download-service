<?php
declare(strict_types=1);

final class ShareStore
{
    public const MAX_UPLOAD_BYTES = 45 * 1024 * 1024;
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
            if (is_link($path) || !is_file($path) || !is_readable($path)) {
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
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
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
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
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

    public function upload(array $upload): string
    {
        $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '文件过大，单个文件最多 45 MB。',
                UPLOAD_ERR_PARTIAL => '文件上传不完整，请重试。',
                UPLOAD_ERR_NO_FILE => '请先选择文件。',
                default => '上传失败，请稍后重试。',
            });
        }
        $name = $upload['name'] ?? '';
        $temp = $upload['tmp_name'] ?? '';
        if (!is_string($name) || !is_string($temp)) {
            throw new InvalidArgumentException('无效的上传请求。');
        }
        $this->assertValidFilename($name);
        if (!is_uploaded_file($temp)) {
            throw new InvalidArgumentException('无效的上传文件。');
        }
        if (filesize($temp) > self::MAX_UPLOAD_BYTES) {
            throw new InvalidArgumentException('文件过大，单个文件最多 45 MB。');
        }
        $this->withLockedStats(function (array &$stats) use ($temp, $name): void {
            $target = $this->filesDir . '/' . $name;
            // Hard-link publication is atomic and cannot overwrite an existing file.
            $staged = $this->filesDir . '/.upload-' . bin2hex(random_bytes(16));
            if (!move_uploaded_file($temp, $staged)) {
                throw new RuntimeException('Could not stage upload.');
            }
            try {
                if (!chmod($staged, 0640)) {
                    throw new RuntimeException('Could not set upload permissions.');
                }
                if (file_exists($target) || is_link($target)) {
                    throw new InvalidArgumentException('已存在同名文件，请重命名后上传。');
                }
                if (!link($staged, $target)) {
                    throw new RuntimeException('Could not publish upload.');
                }
                unset($stats[$name]);
            } finally {
                if (is_file($staged)) unlink($staged);
            }
        });
        return $name;
    }

    public function trash(string $name): void
    {
        $this->assertValidFilename($name);
        $this->withLockedStats(function (array &$stats) use ($name): void {
            $source = $this->filesDir . '/' . $name;
            if (is_link($source) || !is_file($source)) {
                throw new OutOfBoundsException('文件不存在或已被删除。');
            }
            $directory = dirname($this->metadataPath) . '/trash/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(8));
            $this->ensureDir($directory);
            $metadata = ['name' => $name, 'deleted_at' => gmdate('c'), 'stats' => $stats[$name] ?? []];
            if (file_put_contents($directory . '/metadata.json', json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false) {
                throw new RuntimeException('Could not save recycle metadata.');
            }
            if (!rename($source, $directory . '/file')) {
                throw new RuntimeException('Could not move file to recycle directory.');
            }
            unset($stats[$name]);
        });
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
        if ($name === '' || str_starts_with($name, '.') || strlen($name) > 240 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            throw new InvalidArgumentException('文件名不能以点开头、包含控制字符或超过 240 字节。');
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
