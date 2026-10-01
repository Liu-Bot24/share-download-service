<?php
declare(strict_types=1);

/** All public reporting, graph drill-downs and CSV exports share this predicate. */
final class QueryService
{
    public function __construct(private ShareStore $store) {}
    public function range(array $in): array
    {
        $tz = new DateTimeZone($this->store->settings()["timezone"]);
        $today = new DateTimeImmutable("today", $tz);
        $days = in_array((int) ($in["days"] ?? 30), [7, 30, 90], true)
            ? (int) ($in["days"] ?? 30)
            : 30;
        $end = $today;
        $start = $end->modify("-" . ($days - 1) . " days");
        foreach (["start", "end"] as $key) {
            if (!empty($in[$key])) {
                $d = DateTimeImmutable::createFromFormat("!Y-m-d", (string) $in[$key], $tz);
                if (!$d || $d->format("Y-m-d") !== $in[$key]) {
                    throw new ShareError("date", "日期格式应为 YYYY-MM-DD");
                }
                if ($key === "start") {
                    $start = $d;
                } else {
                    $end = $d;
                }
            }
        }
        $days = (int) $start->diff($end)->format("%r%a") + 1;
        if ($days < 1 || $days > 366) {
            throw new ShareError("date", "请选择 1–366 天的范围");
        }
        return [
            "start" => $start->format("Y-m-d"),
            "end" => $end->format("Y-m-d"),
            "days" => $days,
            "timezone" => $tz->getName(),
            "from" => $start->getTimestamp(),
            "until" => $end->modify("+1 day")->getTimestamp(),
        ];
    }
    public function predicate(array $in): array
    {
        $r = $this->range($in);
        $where = ["started_at>=?", "started_at<?"];
        $args = [$r["from"], $r["until"]];
        foreach (["file_id", "region", "referrer", "ip", "status"] as $key) {
            if (isset($in[$key]) && (string) $in[$key] !== "") {
                $where[] = $key . "=?";
                $args[] =
                    $key === "file_id"
                        ? (int) $in[$key]
                        : ($key === "referrer" && $in[$key] === "__direct__"
                            ? ""
                            : (string) $in[$key]);
            }
        }
        if (($in["q"] ?? "") !== "") {
            $where[] = "filename LIKE ?";
            $args[] = "%" . $in["q"] . "%";
        }
        return [implode(" AND ", $where), $args, $r];
    }
    public function events(array $in = [], int $page = 1, int $perPage = 30): array
    {
        [$w, $a, $r] = $this->predicate($in);
        $total = (int) $this->store->db->one("SELECT COUNT(*) n FROM events WHERE " . $w, $a)["n"];
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        $rows = $this->store->db->all(
            "SELECT * FROM events WHERE " .
                $w .
                " ORDER BY started_at DESC,id DESC LIMIT " .
                max(1, min(1000, $perPage)) .
                " OFFSET " .
                ($page - 1) * $perPage,
            $a,
        );
        return [
            "events" => $rows,
            "pagination" => [
                "page" => $page,
                "pages" => $pages,
                "total" => $total,
                "per_page" => $perPage,
            ],
            "range" => $r,
        ];
    }
    public function export(array $in): iterable
    {
        [$w, $a] = $this->predicate($in);
        // Pin membership and release each SQLite read cursor before writing to a slow client.
        $max = (int) $this->store->db->one(
            "SELECT COALESCE(MAX(id),0) n FROM events WHERE " . $w,
            $a,
        )["n"];
        $lastTime = -1;
        $lastId = 0;
        do {
            $batch = $this->store->db->all(
                "SELECT * FROM events WHERE " .
                    $w .
                    " AND id<=? AND (started_at>? OR (started_at=? AND id>?)) ORDER BY started_at,id LIMIT 500",
                array_merge($a, [$max, $lastTime, $lastTime, $lastId]),
            );
            foreach ($batch as $row) {
                $lastTime = (int) $row["started_at"];
                $lastId = (int) $row["id"];
                yield $row;
            }
        } while (count($batch) === 500);
    }
    public function event(int $id): array
    {
        $e = $this->store->db->one("SELECT * FROM events WHERE id=?", [$id]);
        if (!$e) {
            throw new ShareError("not_found", "记录不存在", 404);
        }
        return [
            "event" => $e,
            "transfers" => $this->store->db->all(
                "SELECT t.* FROM transfers t WHERE t.session_id=? ORDER BY id",
                [$e["session_id"]],
            ),
        ];
    }
    public function analytics(array $in = []): array
    {
        [$where, $args, $range] = $this->predicate($in);
        $db = $this->store->db;
        $tz = new DateTimeZone($range["timezone"]);
        $summary = $db->one(
            "SELECT COUNT(*) total,COUNT(DISTINCT ip) unique_ips,COALESCE(SUM(observed_bytes),0) observed_bytes FROM events WHERE " .
                $where,
            $args,
        );
        $all = $db->one(
            "SELECT COALESCE(SUM(public_count),0) lifetime,COALESCE(SUM(legacy_count),0) legacy_total,SUM(CASE WHEN state='active' THEN 1 ELSE 0 END) active,SUM(CASE WHEN state IN ('exhausted','missing','destroy_pending','destroying') THEN 1 ELSE 0 END) attention,COUNT(*) file_total FROM files",
        );
        $summary = array_merge($summary, $all);
        $today = new DateTimeImmutable("today", $tz);
        $summary["today"] = (int) $db->one(
            "SELECT COUNT(*) n FROM events WHERE started_at>=? AND started_at<?",
            [$today->getTimestamp(), $today->modify("+1 day")->getTimestamp()],
        )["n"];
        $summary["last7"] = (int) $db->one(
            "SELECT COUNT(*) n FROM events WHERE started_at>=? AND started_at<?",
            [$today->modify("-6 days")->getTimestamp(), $today->modify("+1 day")->getTimestamp()],
        )["n"];
        $previous = $in;
        $previous["end"] = (new DateTimeImmutable($range["start"], $tz))
            ->modify("-1 day")
            ->format("Y-m-d");
        $previous["start"] = (new DateTimeImmutable($range["start"], $tz))
            ->modify("-" . $range["days"] . " days")
            ->format("Y-m-d");
        [$pw, $pa] = $this->predicate($previous);
        $summary["previous_total"] = (int) $db->one(
            "SELECT COUNT(*) n FROM events WHERE " . $pw,
            $pa,
        )["n"];
        $trend = [];
        $cursor = new DateTimeImmutable($range["start"], $tz);
        for ($i = 0; $i < $range["days"]; $i++) {
            $d = $cursor->modify("+" . $i . " days")->format("Y-m-d");
            $trend[$d] = ["label" => substr($d, 5), "date" => $d, "count" => 0];
        }
        $hours = [];
        for ($h = 0; $h < 24; $h++) {
            $hours[$h] = ["hour" => $h, "count" => 0];
        }
        // Iterate timestamps only; do not load visitor data merely to draw graphs.
        $stmt = $db->run("SELECT started_at FROM events WHERE " . $where, $args);
        while ($row = $stmt->fetch()) {
            $d = (new DateTimeImmutable("@" . $row["started_at"]))->setTimezone($tz);
            $key = $d->format("Y-m-d");
            if (isset($trend[$key])) {
                $trend[$key]["count"]++;
            }
            $hours[(int) $d->format("G")]["count"]++;
        }
        $group = fn(string $key) => $db->all(
            "SELECT " .
                $key .
                " label,COUNT(*) count,COUNT(DISTINCT ip) unique_ips FROM events WHERE " .
                $where .
                " GROUP BY " .
                $key .
                " ORDER BY count DESC,label LIMIT 20",
            $args,
        );
        $regions = $group("region");
        $unknown = $db->one(
            "SELECT '未知' label,COUNT(*) count,COUNT(DISTINCT ip) unique_ips FROM events WHERE " .
                $where .
                " AND region='未知'",
            $args,
        );
        if (
            (int) $unknown["count"] > 0 &&
            !in_array("未知", array_column($regions, "label"), true)
        ) {
            $regions[] = $unknown;
        }
        $withShares = static fn(array $rows): array => array_map(
            static fn(array $row): array => $row + [
                "share" =>
                    (int) $summary["total"] > 0
                        ? round(((int) $row["count"] / (int) $summary["total"]) * 100, 1)
                        : 0,
            ],
            $rows,
        );
        return [
            "summary" => array_map(fn($v) => (int) $v, $summary),
            "trend" => array_values($trend),
            "hours" => $hours,
            "regions" => $withShares($regions),
            "referrers" => $withShares($group("referrer")),
            "files" => $withShares(
                $db->all(
                    "SELECT file_id id,filename name,COUNT(*) count,COUNT(DISTINCT ip) unique_ips FROM events WHERE " .
                        $where .
                        " GROUP BY file_id,filename ORDER BY count DESC,file_id LIMIT 15",
                    $args,
                ),
            ),
            "range" => $range,
        ];
    }
    public function reconcile(): array
    {
        return $this->store->db->all(
            "SELECT f.id,f.name,f.public_count,f.legacy_count,COUNT(e.id) detailed_count FROM files f LEFT JOIN events e ON e.file_id=f.id GROUP BY f.id HAVING f.public_count<>f.legacy_count+COUNT(e.id)",
        );
    }
}
