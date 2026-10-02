<?php
declare(strict_types=1);

/** Serialize catalog mutations and retain a recoverable source until SQLite commits. */
final class FileMutations
{
    private mixed $lock = null;
    public function __construct(private ShareStore $store) {}

    public function run(callable $callback): mixed
    {
        if ($this->lock !== null) {
            return $callback();
        }
        $lock = fopen($this->store->storageDir . "/locks/catalog.lock", "c");
        if (!$lock || !flock($lock, LOCK_EX)) {
            if ($lock) {
                fclose($lock);
            }
            throw new RuntimeException("Cannot lock the file catalog.");
        }
        $this->lock = $lock;
        try {
            foreach (
                $this->store->db->all("SELECT * FROM file_mutations ORDER BY created_at,id")
                as $operation
            ) {
                $this->finish($operation);
            }
            return $callback();
        } finally {
            $this->lock = null;
            fclose($lock);
        }
    }

    public function snapshot(string $source, string $stage, string $sha256): void
    {
        if (is_link($source) || !is_file($source) || file_exists($stage) || is_link($stage)) {
            throw new ShareError("changed", "文件实体已变更，请检查后重试", 409);
        }
        // A hard link is cheap on one volume; exclusive copying also supports separate local volumes.
        if (!@link($source, $stage)) {
            $input = fopen($source, "rb");
            $output = fopen($stage, "xb");
            if (!$input || !$output) {
                if ($input) {
                    fclose($input);
                }
                if ($output) {
                    fclose($output);
                }
                throw new RuntimeException("Cannot stage a recoverable file snapshot.");
            }
            try {
                if (stream_copy_to_stream($input, $output) === false || !fflush($output)) {
                    throw new RuntimeException("Cannot copy the recovery snapshot.");
                }
                if (function_exists("fsync") && !fsync($output)) {
                    throw new RuntimeException("Cannot persist the recovery snapshot.");
                }
            } finally {
                fclose($input);
                fclose($output);
            }
        }
        chmod($stage, 0600);
        if (!hash_equals($sha256, (string) hash_file("sha256", $stage))) {
            throw new ShareError("changed", "文件内容校验不符，操作已停止", 409);
        }
    }

    public function apply(
        string $kind,
        ?int $id,
        string $source,
        string $stage,
        string $target,
        string $sha256,
        callable $commit,
    ): int {
        if ($this->lock === null || !in_array($kind, ["publish", "trash", "restore"], true)) {
            throw new LogicException("File mutation requires the catalog lock.");
        }
        $sourceStat = stat($source);
        $stageStat = stat($stage);
        if (!$sourceStat || !$stageStat) {
            throw new RuntimeException("Recovery snapshot unavailable.");
        }
        $operation = [
            "id" => bin2hex(random_bytes(16)),
            "kind" => $kind,
            "file_id" => $id,
            "committed" => 0,
            "files_dir" => $this->store->filesDir,
            "storage_dir" => realpath($this->store->storageDir),
            "source" => $source,
            "stage" => $stage,
            "target" => $target,
            "source_inode" => $sourceStat["ino"],
            "source_device" => $sourceStat["dev"],
            "stage_inode" => $stageStat["ino"],
            "stage_device" => $stageStat["dev"],
            "sha256" => $sha256,
            "created_at" => time(),
        ];
        $this->store->db->run(
            "INSERT INTO file_mutations(" .
                implode(",", array_keys($operation)) .
                ") VALUES(" .
                implode(",", array_fill(0, count($operation), "?")) .
                ")",
            array_values($operation),
        );
        try {
            if ($kind !== "trash" && !@link($stage, $target)) {
                throw new ShareError("duplicate", "同名文件已存在，请检查后重试", 409);
            }
            $id = $this->store->db->transaction(function () use ($commit, $operation): int {
                $id = $commit();
                $this->store->db->run(
                    "UPDATE file_mutations SET committed=1,file_id=? WHERE id=?",
                    [$id, $operation["id"]],
                );
                return $id;
            });
        } catch (Throwable $error) {
            // The persistent journal also handles a killed process after publication but before commit.
            $this->finish(
                $this->store->db->one("SELECT * FROM file_mutations WHERE id=?", [
                    $operation["id"],
                ]),
            );
            throw $error;
        }
        $this->finish(
            $this->store->db->one("SELECT * FROM file_mutations WHERE id=?", [$operation["id"]]),
        );
        return $id;
    }

    private function finish(array $operation): void
    {
        if (
            $operation["files_dir"] !== $this->store->filesDir ||
            $operation["storage_dir"] !== realpath($this->store->storageDir)
        ) {
            throw new RuntimeException(
                "Pending file operation belongs to different storage; operator reconciliation required.",
            );
        }
        $committed = (bool) $operation["committed"];
        // Never discard the retained copy until the side that must survive is verified.
        // This also covers a missing target after commit or a changed source before rollback.
        if ($committed && !$this->owns($operation["target"], $operation, "stage")) {
            throw new RuntimeException(
                "Committed file target is unavailable; recovery source retained for reconciliation.",
            );
        }
        if (
            !$committed &&
            in_array($operation["kind"], ["trash", "restore"], true) &&
            !$this->owns($operation["source"], $operation, "source")
        ) {
            throw new RuntimeException(
                "Original source is unavailable; recovery snapshot retained for reconciliation.",
            );
        }
        if ($operation["kind"] === "trash") {
            $this->removeOwned(
                $operation[$committed ? "source" : "stage"],
                $operation,
                $committed ? "source" : "stage",
            );
        } elseif (in_array($operation["kind"], ["publish", "restore"], true)) {
            if (!$committed) {
                $this->removeOwned($operation["target"], $operation, "stage", false);
            } elseif ($operation["kind"] === "restore") {
                $this->removeOwned($operation["source"], $operation, "source");
            }
            $this->removeOwned($operation["stage"], $operation, "stage");
        } else {
            throw new RuntimeException("Unknown pending file operation.");
        }
        $this->store->db->run("DELETE FROM file_mutations WHERE id=?", [$operation["id"]]);
    }

    private function removeOwned(
        string $path,
        array $operation,
        string $identity,
        bool $requireMatch = true,
    ): void {
        clearstatcache(true, $path);
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (!$this->owns($path, $operation, $identity)) {
            if (!$requireMatch) {
                return; // A competing external file is never ours to remove.
            }
            throw new RuntimeException(
                "Pending file operation found a changed entity; preserved for operator reconciliation.",
            );
        }
        if (!unlink($path)) {
            throw new RuntimeException("Cannot finalize a recoverable file operation.");
        }
    }

    private function owns(string $path, array $operation, string $identity): bool
    {
        clearstatcache(true, $path);
        $parent = realpath(dirname($path));
        $trashRoot = realpath($this->store->storageDir . "/trash");
        $allowed =
            $parent === $this->store->filesDir ||
            ($parent !== false &&
                $trashRoot !== false &&
                str_starts_with($parent, $trashRoot . "/"));
        $stat = !$allowed || is_link($path) || !is_file($path) ? false : stat($path);
        return $stat &&
            $stat["ino"] === (int) $operation[$identity . "_inode"] &&
            $stat["dev"] === (int) $operation[$identity . "_device"] &&
            hash_equals($operation["sha256"], (string) hash_file("sha256", $path));
    }
}
