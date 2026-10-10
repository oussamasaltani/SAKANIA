<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Durable recurring-problem and fix-outcome accumulator.
 *
 * PHP-FPM has no process-wide request lifetime, so the polling fingerprint,
 * rows, cadence and baselines all live in Store. Store::update serialises the
 * complete read/modify/write operation across workers. Finding names take part
 * only in the local polling fingerprint and never enter a row or payload.
 */
final class Candidates
{
    public const MIN_OCCURRENCES_TO_SURFACE = 2;
    public const MAX_CANDIDATES = 100;
    public const MAX_BATCH = 50;

    /** The server answered. */
    public const STATUS_SUBMITTED = 'submitted';
    /** The bytes went out on a SAPI that could not wait for the answer. */
    public const STATUS_UNCONFIRMED = 'submitted-unconfirmed';

    private const KEY = 'problem_candidates';
    private const BASELINES_KEY = 'problem_baselines';
    private const LAST_TICK_KEY = 'problem_last_tick';
    private const INTERVAL_MS = 60000;

    private static bool $active = false;

    private function __construct()
    {
    }

    public static function start(): void
    {
        if (!RuntimeFlags::disabled()) {
            self::$active = true;
        }
    }

    public static function stop(): void
    {
        self::$active = false;
    }

    /** @return list<array<string,mixed>> */
    public static function collectReportableFindings(): array
    {
        if (RuntimeFlags::disabled()) {
            return [];
        }
        $rows = Snapshot::buildRows(Samples::all());
        $findings = Snapshot::buildCrossCutting($rows);
        foreach (LiveDetectors::collectDetectorFindings() as $finding) {
            $findings[] = $finding;
        }

        return $findings;
    }

    /**
     * Run from the post-response flush. Its cadence is durable because a PHP
     * worker may disappear after every request.
     */
    public static function flushIfDue(): bool
    {
        if (!self::$active || RuntimeFlags::disabled()) {
            return false;
        }
        $now = Telemetry::nowMs();
        $due = Store::update(self::LAST_TICK_KEY, static function ($cur) use ($now): int {
            $last = is_int($cur) ? $cur : 0;
            return $last === 0 || ($now - $last) >= self::INTERVAL_MS ? $now : $last;
        }, 0);
        if ($due !== $now) {
            return false;
        }

        self::ingest(self::collectReportableFindings());
        return true;
    }

    /** Immediate best-effort pass, useful to explicit flush callers. */
    public static function reportNow(): int
    {
        if (RuntimeFlags::disabled()) {
            return 0;
        }

        return count(self::ingest(self::collectReportableFindings()));
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @return list<array<string,mixed>>
     */
    public static function ingest(array $findings): array
    {
        // Gate one is deliberately before every Store read.
        if (RuntimeFlags::disabled()) {
            return [];
        }
        $known = [];
        foreach ($findings as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $kind = (string) ($finding['kind'] ?? '');
            if (!ProblemKinds::contains($kind)) {
                continue;
            }
            $known[] = [
                'kind' => $kind,
                'name' => (string) ($finding['name'] ?? ''),
                'p95' => (int) round((float) ($finding['p95'] ?? 0)),
                'count' => max(0, (int) ($finding['count'] ?? 0)),
            ];
        }
        if ($known === []) {
            return self::surfaceable(self::rows());
        }

        $parts = [];
        foreach ($known as $finding) {
            $parts[] = $finding['kind'] . '|' . $finding['name'] . '|'
                . $finding['p95'] . '|' . $finding['count'];
        }
        sort($parts, SORT_STRING);
        // Hashed before it is stored, and only ever compared for equality. The
        // parts above carry finding names, and Store is a FILE: it is read by
        // people, swept into backups and pasted into support threads. The rows
        // beside this value hold buckets only, so this is the one place a route
        // or a hostname could have reached durable state. It does not.
        $fingerprint = hash('sha256', implode("\n", $parts));
        $now = Telemetry::nowMs();

        $state = Store::update(self::KEY, static function ($cur) use ($known, $fingerprint, $now): array {
            $state = is_array($cur) ? $cur : [];
            $rows = isset($state['rows']) && is_array($state['rows']) ? $state['rows'] : [];
            if (($state['lastFingerprint'] ?? null) === $fingerprint) {
                return ['lastFingerprint' => $fingerprint, 'rows' => $rows];
            }
            $byId = [];
            foreach ($rows as $index => $row) {
                if (is_array($row) && isset($row['id'])) {
                    $byId[(string) $row['id']] = $index;
                }
            }
            foreach ($known as $finding) {
                $signature = self::signatureFor($finding);
                $id = self::safeId($signature);
                if (isset($byId[$id])) {
                    $i = $byId[$id];
                    $rows[$i]['occurrences'] = (int) ($rows[$i]['occurrences'] ?? 0) + 1;
                    $rows[$i]['lastSeenAt'] = $now;
                    continue;
                }
                if (count($rows) >= self::MAX_CANDIDATES) {
                    continue;
                }
                $rows[] = [
                    'id' => $id,
                    'signature' => $signature,
                    'kind' => $finding['kind'],
                    'severityBucket' => self::bucketSeverity($finding['p95']),
                    'countBucket' => self::bucketCount($finding['count']),
                    'occurrences' => 1,
                    'firstSeenAt' => $now,
                    'lastSeenAt' => $now,
                    'status' => 'new',
                ];
                $byId[$id] = count($rows) - 1;
            }
            usort($rows, static fn ($a, $b) =>
                (int) ($b['lastSeenAt'] ?? 0) <=> (int) ($a['lastSeenAt'] ?? 0));

            return ['lastFingerprint' => $fingerprint, 'rows' => $rows];
        }, ['lastFingerprint' => null, 'rows' => []]);

        $rows = is_array($state) && is_array($state['rows'] ?? null) ? $state['rows'] : [];
        self::reportFixOutcomes($known);
        self::reportCandidates($rows);

        return self::surfaceable(self::rows());
    }

    /** @param array<string,mixed> $finding */
    public static function signatureFor(array $finding): string
    {
        return (string) ($finding['kind'] ?? '') . ':'
            . self::bucketSeverity((int) ($finding['p95'] ?? 0)) . ':'
            . self::bucketCount((int) ($finding['count'] ?? 0));
    }

    public static function bucketSeverity(int $p95Ms): string
    {
        if ($p95Ms <= 100) {
            return 'low';
        }
        if ($p95Ms <= 500) {
            return 'med';
        }
        return 'high';
    }

    public static function bucketCount(int $count): string
    {
        if ($count < 3) {
            return '<3';
        }
        if ($count < 10) {
            return '<10';
        }
        if ($count < 50) {
            return '<50';
        }
        return '50+';
    }

    public static function safeId(string $signature): string
    {
        $h = 5381;
        $length = strlen($signature);
        for ($i = 0; $i < $length; $i++) {
            $h = (($h * 33) ^ ord($signature[$i])) & 0xffffffff;
        }

        return 'cr_' . dechex($h);
    }

    /** @return list<array<string,mixed>> */
    public static function rows(): array
    {
        if (RuntimeFlags::disabled()) {
            return [];
        }
        $state = Store::get(self::KEY, []);
        return is_array($state) && is_array($state['rows'] ?? null)
            ? array_values(array_filter($state['rows'], 'is_array')) : [];
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private static function surfaceable(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            static fn ($row) => (int) ($row['occurrences'] ?? 0) >= self::MIN_OCCURRENCES_TO_SURFACE
        ));
    }

    /** @param list<array<string,mixed>> $rows */
    private static function reportCandidates(array $rows): void
    {
        $batch = [];
        $ids = [];
        foreach ($rows as $row) {
            if ((int) ($row['occurrences'] ?? 0) < self::MIN_OCCURRENCES_TO_SURFACE
                || ($row['status'] ?? '') !== 'new') {
                continue;
            }
            $batch[] = [
                'signature' => (string) $row['signature'],
                'kind' => (string) $row['kind'],
                'severityBucket' => (string) $row['severityBucket'],
                'countBucket' => (string) $row['countBucket'],
                'occurrences' => (int) $row['occurrences'],
            ];
            $ids[] = (string) $row['id'];
            if (count($batch) >= self::MAX_BATCH) {
                break;
            }
        }
        $result = self::send(
            RuntimeFlags::apiBase() . '/candidates',
            'signatures',
            $batch
        );
        $accepted = $result['accepted'];
        if ($accepted <= 0) {
            return;
        }
        // "submitted" means the server said so. On a SAPI that cannot hand the
        // page back first, nobody read the answer, and the row says exactly
        // that rather than claiming a confirmation it never got.
        $status = $result['confirmed'] ? self::STATUS_SUBMITTED : self::STATUS_UNCONFIRMED;
        $sent = array_fill_keys(array_slice($ids, 0, $accepted), true);
        Store::update(self::KEY, static function ($cur) use ($sent, $status): array {
            $state = is_array($cur) ? $cur : [];
            $rows = is_array($state['rows'] ?? null) ? $state['rows'] : [];
            foreach ($rows as &$row) {
                if (is_array($row) && isset($sent[(string) ($row['id'] ?? '')])) {
                    $row['status'] = $status;
                }
            }
            unset($row);
            $state['rows'] = $rows;
            return $state;
        }, []);
    }

    /** @param list<array<string,mixed>> $findings */
    private static function reportFixOutcomes(array $findings): void
    {
        $rank = ['low' => 0, 'med' => 1, 'high' => 2];
        $worstNow = [];
        $maxCount = [];
        foreach ($findings as $finding) {
            $kind = $finding['kind'];
            $sev = self::bucketSeverity($finding['p95']);
            if (!isset($worstNow[$kind]) || $rank[$sev] > $rank[$worstNow[$kind]]) {
                $worstNow[$kind] = $sev;
            }
            $maxCount[$kind] = max($maxCount[$kind] ?? 0, $finding['count']);
        }
        ksort($worstNow);
        $outcomes = [];
        Store::update(self::BASELINES_KEY, static function ($cur) use (
            $worstNow,
            $maxCount,
            $rank,
            &$outcomes
        ): array {
            $baselines = is_array($cur) ? $cur : [];
            foreach ($worstNow as $kind => $sev) {
                $before = $baselines[$kind] ?? null;
                if (is_string($before) && isset($rank[$before]) && $rank[$sev] < $rank[$before]) {
                    $outcomes[] = [
                        'ruleId' => $kind,
                        'kind' => $kind,
                        'beforeRating' => self::rating($before),
                        'afterRating' => self::rating($sev),
                        'occurrences' => 1,
                        'severityBucket' => $before,
                        'countBucket' => self::bucketCount($maxCount[$kind]),
                    ];
                } elseif (!is_string($before) || !isset($rank[$before]) || $rank[$sev] > $rank[$before]) {
                    $baselines[$kind] = $sev;
                }
            }
            return $baselines;
        }, []);
        $outcomes = array_slice($outcomes, 0, self::MAX_BATCH);
        $accepted = self::send(
            RuntimeFlags::apiBase() . '/resolutions',
            'resolutions',
            $outcomes
        )['accepted'];
        if ($accepted <= 0) {
            return;
        }
        $acceptedRows = array_slice($outcomes, 0, $accepted);
        Store::update(self::BASELINES_KEY, static function ($cur) use ($acceptedRows, $worstNow): array {
            $baselines = is_array($cur) ? $cur : [];
            $rank = ['low' => 0, 'med' => 1, 'high' => 2];
            foreach ($acceptedRows as $row) {
                $kind = (string) $row['kind'];
                $current = $baselines[$kind] ?? null;
                $reportedBefore = (string) ($row['severityBucket'] ?? '');
                // Do not let an older accepted improvement erase a worsening
                // observed by another FPM worker while the request was in flight.
                if (!is_string($current)
                    || !isset($rank[$current], $rank[$reportedBefore])
                    || $rank[$current] <= $rank[$reportedBefore]) {
                    $baselines[$kind] = $worstNow[$kind];
                }
            }
            return $baselines;
        }, []);
    }

    private static function rating(string $severity): string
    {
        return $severity === 'high' ? 'poor' : ($severity === 'med' ? 'needs-work' : 'good');
    }

    /**
     * Send one batch and say how much of it went, and whether the server said
     * so or we merely wrote the bytes.
     *
     * The visitor decides which of those two it is. Under FPM and LiteSpeed the
     * response has already left by the time this runs, so the kit can afford to
     * wait for the answer and record a confirmed submission. Under every other
     * SAPI the connection is still open and a slow ingest host would be added
     * to somebody's page load, so the batch goes out without a read — see
     * Transmit::postNoWait(). Reporting is never worth a customer's second.
     *
     * @param list<array<string,mixed>> $batch
     * @return array{accepted:int,confirmed:bool}
     */
    private static function send(string $url, string $field, array $batch): array
    {
        $nothing = ['accepted' => 0, 'confirmed' => true];
        if ($batch === [] || RuntimeFlags::disabled()) {
            return $nothing;
        }
        $installId = Telemetry::installId();
        $token = Telemetry::deleteToken();
        if ($installId === null || $token === null) {
            return $nothing;
        }
        $payload = [
            'installId' => $installId,
            'packageVersion' => Snapshot::pep440ToSemver(Thresholds::RUNTIME_VERSION),
            $field => $batch,
        ];

        if (!Instrument::responseHandedBack()) {
            $written = (bool) Safe::run(
                static fn (): bool => Transmit::postNoWait($url, $payload, $token),
                false
            );

            return [
                'accepted' => $written ? count($batch) : 0,
                'confirmed' => false,
            ];
        }

        $accepted = (int) Safe::run(static function () use ($url, $payload, $batch, $token): int {
            $res = Transmit::safeTransmit($url, $payload, $token, 5000);
            $status = (int) ($res['status'] ?? 0);
            if ($status < 200 || $status >= 300) {
                return 0;
            }
            $bodyAccepted = $res['body']['accepted'] ?? null;
            return is_int($bodyAccepted) ? min(count($batch), max(0, $bodyAccepted)) : count($batch);
        }, 0);

        return ['accepted' => $accepted, 'confirmed' => true];
    }

    public static function clear(): void
    {
        self::stop();
        Store::delete(self::KEY);
        Store::delete(self::BASELINES_KEY);
        Store::delete(self::LAST_TICK_KEY);
    }

    /** Test seam: inspect the persisted worst-ever severity map. */
    public static function baselines(): array
    {
        if (RuntimeFlags::disabled()) {
            return [];
        }
        $value = Store::get(self::BASELINES_KEY, []);
        return is_array($value) ? $value : [];
    }

    public static function active(): bool
    {
        return self::$active;
    }
}