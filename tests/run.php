<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/ShareStore.php';

final class Assert
{
    public static function same(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
        }
    }

    public static function true(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}

$tests = [];

$tests['lists regular files from the mapped directory with zero downloads'] = function (): void {
    $tmp = make_temp_dir();
    $filesDir = $tmp . '/files';
    mkdir($filesDir, 0700, true);
    $source = $filesDir . '/Course Nav Export.json';
    file_put_contents($source, '{"format":"course-navigator-share"}');
    file_put_contents($filesDir . '/.hidden.json', '{}');
    mkdir($filesDir . '/folder');

    $store = new ShareStore($filesDir, $tmp . '/data/stats.json');
    $files = $store->listFiles();

    Assert::same(1, count($files), 'one file should be listed');
    Assert::same('Course Nav Export.json', $files[0]['name'], 'original filename should be preserved');
    Assert::same(0, $files[0]['downloads'], 'new share should start at zero downloads');
    Assert::same(hash_file('sha256', $source), $files[0]['sha256'], 'hash should describe stored content');
    Assert::same(filesize($source), $files[0]['bytes'], 'listed file should expose byte size');
};

$tests['records a public download exactly once and exposes the updated count'] = function (): void {
    $tmp = make_temp_dir();
    $filesDir = $tmp . '/files';
    mkdir($filesDir, 0700, true);
    $source = $filesDir . '/course.json';
    file_put_contents($source, '{"ok":true}');

    $store = new ShareStore($filesDir, $tmp . '/data/stats.json');

    $download = $store->recordDownload('course.json');
    $files = $store->listFiles();

    Assert::same('course.json', $download['name'], 'download should return the requested record');
    Assert::same(1, $download['downloads'], 'returned download record should include incremented count');
    Assert::same(1, $files[0]['downloads'], 'list should include incremented count');
    Assert::true(is_string($files[0]['last_downloaded_at']) && $files[0]['last_downloaded_at'] !== '', 'download should store timestamp');
};

$tests['rejects path traversal style names without changing counts'] = function (): void {
    $tmp = make_temp_dir();
    $filesDir = $tmp . '/files';
    mkdir($filesDir, 0700, true);
    $source = $filesDir . '/course.json';
    file_put_contents($source, '{"ok":true}');

    $store = new ShareStore($filesDir, $tmp . '/data/stats.json');

    try {
        $store->recordDownload('../course.json');
        throw new RuntimeException('path traversal name should have failed');
    } catch (InvalidArgumentException) {
    }

    Assert::same(0, $store->listFiles()[0]['downloads'], 'failed lookup should not increment downloads');
};

$tests['does not list stale stats for files removed from the mapped directory'] = function (): void {
    $tmp = make_temp_dir();
    $filesDir = $tmp . '/files';
    mkdir($filesDir, 0700, true);
    $source = $filesDir . '/course.json';
    file_put_contents($source, '{"ok":true}');

    $store = new ShareStore($filesDir, $tmp . '/data/stats.json');
    $store->recordDownload('course.json');
    unlink($source);

    Assert::same([], $store->listFiles(), 'removed files should disappear from dashboard');
};

$tests['deletion preserves content and download metadata in recycle directory'] = function (): void {
    $tmp = make_temp_dir();
    $store = new ShareStore($tmp . '/files', $tmp . '/storage/stats.json');
    file_put_contents($tmp . '/files/中文 文件.txt', 'recover me');
    $store->recordDownload('中文 文件.txt');
    $store->trash('中文 文件.txt');
    Assert::same([], $store->listFiles(), 'deleted file must disappear');
    Assert::same(null, $store->get('中文 文件.txt'), 'deleted link must stop working');
    $archives = glob($tmp . '/storage/trash/*');
    Assert::same(1, count($archives), 'one recoverable archive');
    Assert::same('recover me', file_get_contents($archives[0] . '/file'), 'content preserved');
    $meta = json_decode(file_get_contents($archives[0] . '/metadata.json'), true);
    Assert::same(1, $meta['stats']['downloads'], 'download metadata preserved');
    file_put_contents($tmp . '/files/中文 文件.txt', 'new version');
    Assert::same(0, $store->get('中文 文件.txt')['downloads'], 'replacement starts fresh');
};

$tests['rejects hidden names traversal control characters and symbolic links'] = function (): void {
    $tmp = getenv('SHARE_TEST_SYMLINK_DIR') ?: make_temp_dir();
    $store = new ShareStore($tmp . '/files', $tmp . '/storage/stats.json');
    file_put_contents($tmp . '/outside.txt', 'outside');
    if (!is_link($tmp . '/files/link.txt')) {
        symlink($tmp . '/outside.txt', $tmp . '/files/link.txt');
    }
    Assert::same([], $store->listFiles(), 'links must not be listed');
    Assert::same(null, $store->get('link.txt'), 'links cannot be downloaded');
    foreach (['../outside.txt', '.env', "bad\nname", 'folder/file', 'folder\\file'] as $name) {
        try {
            $store->trash($name);
            throw new RuntimeException('unsafe filename was accepted');
        } catch (InvalidArgumentException) {}
    }
    try {
        $store->trash('link.txt');
        throw new RuntimeException('symlink deletion was accepted');
    } catch (OutOfBoundsException) {}
    Assert::same('outside', file_get_contents($tmp . '/outside.txt'), 'outside file unchanged');
};

$tests['rejects incomplete and forged uploads'] = function (): void {
    $tmp = make_temp_dir();
    $store = new ShareStore($tmp . '/files', $tmp . '/storage/stats.json');
    file_put_contents($tmp . '/fake.txt', 'fake');
    foreach ([['error' => UPLOAD_ERR_PARTIAL], ['error' => UPLOAD_ERR_OK, 'name' => 'fake.txt', 'tmp_name' => $tmp . '/fake.txt']] as $upload) {
        try {
            $store->upload($upload);
            throw new RuntimeException('invalid upload was accepted');
        } catch (InvalidArgumentException) {}
    }
    Assert::same([], $store->listFiles(), 'invalid uploads must not appear');
};

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL {$name}\n{$e->getMessage()}\n";
    }
}

if ($failures > 0) {
    exit(1);
}

function make_temp_dir(): string
{
    $base = sys_get_temp_dir() . '/share-download-test-' . bin2hex(random_bytes(6));
    if (!mkdir($base, 0700, true) && !is_dir($base)) {
        throw new RuntimeException('Could not create temp dir');
    }
    register_shutdown_function(static function () use ($base): void {
        remove_tree($base);
    });
    return $base;
}

function remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}
