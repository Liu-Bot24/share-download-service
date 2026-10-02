<?php
declare(strict_types=1);

// Independent merge-gate probes. Only synthetic, randomly named temporary files.
require_once __DIR__ . '/../src/ShareStore.php';

$mgRoots = [];
$mgFailures = 0;
$mgAssertions = 0;

function mgAssert(bool $condition, string $message): void
{
    global $mgAssertions;
    $mgAssertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function mgFixture(): array
{
    global $mgRoots;
    $root = sys_get_temp_dir() . '/share-merge-gate-' . bin2hex(random_bytes(12));
    $mgRoots[] = $root;
    mkdir($root . '/files', 0700, true);
    mkdir($root . '/storage', 0700, true);
    file_put_contents($root . '/files/recovery.txt', 'original');
    file_put_contents($root . '/storage/stats.json', json_encode([
        'recovery.txt' => ['downloads' => 7],
    ], JSON_THROW_ON_ERROR));
    $store = new ShareStore($root . '/files', $root . '/storage/stats.json');
    return [$store, $store->resolve('recovery.txt'), $root];
}

function mgActual(string $case, array $values): void
{
    echo 'ACTUAL ' . $case . ' ' . json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
}

$mgTests = [];
$mgTests['restore does not orphan the recovery entity when another missing record owns the name'] = function (): void {
    [$store, $original, $root] = mgFixture();
    $store->trash($original['id'], 'merge-gate');
    $trashed = $store->file($original['id']);
    $source = $root . '/storage/trash/' . $trashed['trash_key'] . '/file';
    $target = $root . '/files/recovery.txt';

    // A second same-name upload/import has its own stable identity, then disappears.
    file_put_contents($target, 'new file');
    $store->scan('merge-gate');
    $replacement = $store->db->one('SELECT id FROM files WHERE storage_name=? AND state=\'active\'', ['recovery.txt']);
    mgAssert($replacement !== null && (int) $replacement['id'] !== $original['id'], 'The replacement must have an independent record');
    unlink($target);
    $store->scan('merge-gate');
    mgAssert($store->file((int) $replacement['id'])['state'] === 'missing', 'The replacement must be missing before restore');

    $error = null;
    try {
        $store->restore($original['id'], 'merge-gate');
    } catch (Throwable $e) {
        $error = get_class($e) . ': ' . $e->getMessage();
    }
    $after = $store->file($original['id']);
    mgActual('missing-name-owner', [
        'error' => $error,
        'original_state' => $after['state'],
        'recovery_entity_exists' => is_file($source),
        'target_exists' => is_file($target),
        'original_count' => $after['public_count'],
        'replacement_state' => $store->file((int) $replacement['id'])['state'],
    ]);

    // Either reject before moving bytes, or reconcile both identities and restore cleanly.
    $cleanRejection = $error !== null && $after['state'] === 'trashed' && is_file($source) && !file_exists($target);
    $cleanRestore = $error === null && $after['state'] === 'paused' && !file_exists($source) && $store->readable($after)
        && hash_file('sha256', $target) === $original['sha256'];
    mgAssert($cleanRejection || $cleanRestore, 'Restore must not move the entity out of trash and then roll back only its database state');
    mgAssert($after['public_count'] === 7, 'Original historical count must survive');
};

$mgTests['restore rejects a same-length modified recovery entity before serving a stale checksum'] = function (): void {
    [$store, $original, $root] = mgFixture();
    $store->trash($original['id'], 'merge-gate');
    $trashed = $store->file($original['id']);
    $source = $root . '/storage/trash/' . $trashed['trash_key'] . '/file';
    $target = $root . '/files/recovery.txt';
    file_put_contents($source, 'tampered');
    touch($source, $original['mtime']);
    clearstatcache(true, $source);
    mgAssert(filesize($source) === $original['bytes'], 'Mutation fixture must preserve the original byte length');
    mgAssert(hash_file('sha256', $source) !== $original['sha256'], 'Mutation fixture must change content');

    $error = null;
    try {
        $store->restore($original['id'], 'merge-gate');
    } catch (Throwable $e) {
        $error = get_class($e) . ': ' . $e->getMessage();
    }
    $after = $store->file($original['id']);
    mgActual('modified-recovery-entity', [
        'error' => $error,
        'state' => $after['state'],
        'recovery_entity_exists' => is_file($source),
        'target_exists' => is_file($target),
        'readable_by_store' => $store->readable($after),
        'persisted_sha256' => $after['sha256'],
        'actual_target_sha256' => is_file($target) ? hash_file('sha256', $target) : null,
    ]);
    mgAssert($error !== null && $after['state'] === 'trashed' && is_file($source) && !file_exists($target),
        'Recovery content that disagrees with the retained SHA256 must be rejected safely');
    mgAssert($after['sha256'] === $original['sha256'] && $after['public_count'] === 7,
        'Recovery validation must preserve the original checksum and history');
};

foreach ($mgTests as $name => $test) {
    try {
        $test();
        echo 'PASS ' . $name . "\n";
    } catch (Throwable $e) {
        $mgFailures++;
        echo 'FAIL ' . $name . ': ' . $e->getMessage() . "\n";
    }
}

// All roots were created by this process and are under the system temporary directory.
foreach ($mgRoots as $root) {
    if (!str_starts_with($root, sys_get_temp_dir() . '/share-merge-gate-') || is_link($root)) {
        throw new RuntimeException('Refusing cleanup outside this test fixture');
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($root);
}
echo count($mgTests) . ' tests, ' . $mgAssertions . ' assertions, ' . $mgFailures . " failures\n";
exit($mgFailures > 0 ? 1 : 0);
