<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * The outbound side of the screen-bearing channels: spans and perf snapshots.
 *
 * Everything here runs after the response has left the building (see
 * Instrument::flush), and everything here is gated on full telemetry. A project
 * that has not turned sharing on uploads neither of these, ever.
 */
final class Uploader
{
    /** Never ship more than this many spans in one call. */
    public const MAX_SPANS_PER_CALL = 50;
    public const EXIT_ACTIVATION_TIMEOUT_MS = 900;

    private static ?string $lastExitActivationOutcome = null;

    private function __construct()
    {
    }

    /**
     * Upload one server-sized sample batch when work or cadence makes it due.
     * The batch was already removed before transport and is deliberately never
     * put back: refusal must not turn a dead server into unbounded host memory.
     */
    public static function flushSamples(bool $force = false, int $timeoutMs = 5000): bool
    {
        return (bool) Safe::run(static function () use ($force, $timeoutMs): bool {
            if (!self::canUpload()) {
                if (!self::canRetainSamplesWhileLocked()) {
                    SampleUploader::clear();
                }
                return false;
            }
            if (!$force && !SampleUploader::flushDue()) {
                return false;
            }
            $samples = SampleUploader::drainBatch();
            if ($samples === []) {
                return false;
            }
            SampleUploader::markFlushAttempted();
            $installId = Telemetry::installId();
            $token = Telemetry::deleteToken();
            if ($installId === null || $token === null) {
                return false;
            }
            try {
                $res = Transmit::safeTransmit(
                    RuntimeFlags::apiBase() . '/samples',
                    [
                        'installId' => $installId,
                        'packageVersion' => Snapshot::pep440ToSemver(Thresholds::RUNTIME_VERSION),
                        'samples' => $samples,
                    ],
                    $token,
                    max(1, $timeoutMs)
                );
            } catch (\Throwable $e) {
                Telemetry::noteUploadRejected('unreachable');
                return false;
            }
            $status = (int) $res['status'];
            if ($status >= 200 && $status < 300) {
                Telemetry::noteUploadAccepted();
                Telemetry::noteServerDrops(is_array($res['body']) ? $res['body'] : null);
                return true;
            }
            Telemetry::noteUploadRejected(
                $status === 0 ? 'unreachable'
                    : (($status === 401 || $status === 403) ? 'unauthorized'
                        : ($status >= 500 ? 'server-error' : 'rejected'))
            );

            return false;
        }, false);
    }

    /**
     * Drain buffered measurements in this calling thread under one wall-clock
     * budget. A failed batch ends the drain, so an unreachable endpoint costs
     * one bounded attempt rather than one attempt per queued batch.
     */
    public static function flushSamplesBlocking(int $budgetMs = 2000): int
    {
        $sent = 0;
        $deadline = hrtime(true) + max(0, $budgetMs) * 1_000_000;
        try {
            self::$lastExitActivationOutcome = self::activateForExit($deadline);
            while (hrtime(true) < $deadline && SampleUploader::count() > 0) {
                $before = SampleUploader::count();
                $remainingMs = (int) max(0, floor(($deadline - hrtime(true)) / 1_000_000));
                if ($remainingMs <= 0 || !self::flushSamples(true, $remainingMs)) {
                    break;
                }
                $sent += min($before, SampleUploader::MAX_BATCH);
            }
        } catch (\Throwable $e) {
            // A shutdown flush may never replace the host's own exit failure.
        }

        return $sent;
    }

    public static function lastExitActivationOutcome(): ?string
    {
        return self::$lastExitActivationOutcome;
    }

    private static function activateForExit(int $deadline): string
    {
        if (KillSwitch::isActive()) {
            return 'already';
        }
        if (Telemetry::installId() === null
            || (Telemetry::deleteToken() === null && Telemetry::readToken() === null)) {
            return 'no-config';
        }
        $remainingMs = (int) max(0, floor(($deadline - hrtime(true)) / 1_000_000));
        if ($remainingMs <= 0) {
            return 'no-budget';
        }
        $answer = KillSwitch::check(
            Integrity::status(),
            true,
            min(self::EXIT_ACTIVATION_TIMEOUT_MS, $remainingMs)
        );

        return $answer ?? 'unreachable';
    }

    /**
     * Send buffered spans. The buffer is drained in one locked step, so two
     * workers flushing at the same moment cannot upload the same span twice;
     * a failed send puts them back.
     */
    public static function flushSpans(): bool
    {
        return (bool) Safe::run(static function (): bool {
            if (!self::canUpload()) {
                return false;
            }
            $spans = SpanEmitter::drain();
            if ($spans === []) {
                return false;
            }
            if (count($spans) > self::MAX_SPANS_PER_CALL) {
                // Keep the newest; the oldest are the least useful.
                $spans = array_slice($spans, -self::MAX_SPANS_PER_CALL);
            }
            $installId = Telemetry::installId();
            $token = Telemetry::deleteToken();
            if ($installId === null || $token === null) {
                return false;
            }
            try {
                $res = Transmit::safeTransmit(
                    RuntimeFlags::apiBase() . '/spans',
                    [
                        'installId' => $installId,
                        'packageVersion' => Snapshot::pep440ToSemver(Thresholds::RUNTIME_VERSION),
                        'spans' => $spans,
                    ],
                    $token,
                    5000
                );
            } catch (\Throwable $e) {
                Telemetry::noteUploadRejected('unreachable');
                self::requeueSpans($spans);
                return false;
            }
            $status = (int) $res['status'];
            if ($status >= 200 && $status < 300) {
                Telemetry::noteUploadAccepted();
                // A 202 is no longer read as unqualified success: the reply the
                // server already handed back may report rows it accepted the
                // batch for and then refused. Self-guarded and off the request
                // path — the body is already in hand, so reading it can neither
                // slow nor fail this upload.
                Telemetry::noteServerDrops(is_array($res['body']) ? $res['body'] : null);

                return true;
            }
            Telemetry::noteUploadRejected(
                $status === 0 ? 'unreachable'
                    : (($status === 401 || $status === 403) ? 'unauthorized'
                        : ($status >= 500 ? 'server-error' : 'rejected'))
            );
            // A semantic 404 means this install is gone from the server's point
            // of view; there is nothing to put back.
            if ($status !== 404) {
                self::requeueSpans($spans);
            }

            return false;
        }, false);
    }

    /** @param list<array<string,mixed>> $spans */
    private static function requeueSpans(array $spans): void
    {
        foreach ($spans as $span) {
            if (!is_array($span)) {
                continue;
            }
            SpanEmitter::record(
                (string) ($span['traceId'] ?? ''),
                (string) ($span['routeLabel'] ?? ''),
                (float) ($span['durationMs'] ?? 0),
                (float) ($span['startOffsetMs'] ?? 0),
                $span['spanId'] ?? null,
                $span['parentSpanId'] ?? null,
                $span['kind'] ?? null,
                $span['outcome'] ?? null
            );
        }
    }

    /**
     * Upload a perf snapshot when the cadence says one is due and there is
     * something in it. An empty snapshot from a just-installed app is skipped —
     * we never ship a content-free payload.
     */
    public static function flushSnapshotIfDue(): bool
    {
        return (bool) Safe::run(static function (): bool {
            if (!self::canUpload()) {
                return false;
            }
            if (!Snapshot::uploadDue() || !Snapshot::worthUploading()) {
                return false;
            }

            return self::uploadSnapshot();
        }, false);
    }

    /**
     * Force one snapshot upload regardless of cadence. Regardless of CADENCE —
     * never regardless of the gates: a project that has not turned sharing on
     * has no force path.
     */
    public static function uploadSnapshot(): bool
    {
        return (bool) Safe::run(static function (): bool {
            if (!self::canUpload()) {
                return false;
            }
            $installId = Telemetry::installId();
            $token = Telemetry::deleteToken();
            if ($installId === null || $token === null) {
                return false;
            }
            $snapshot = Snapshot::capturePerfSnapshot();
            try {
                $res = Transmit::safeTransmit(
                    RuntimeFlags::apiBase() . '/snapshots',
                    [
                        'installId' => $installId,
                        'packageVersion' => Snapshot::pep440ToSemver(Thresholds::RUNTIME_VERSION),
                        'snapshot' => $snapshot,
                    ],
                    $token,
                    8000
                );
            } catch (\Throwable $e) {
                Telemetry::noteUploadRejected('unreachable');
                return false;
            }
            $status = (int) $res['status'];
            if ($status >= 200 && $status < 300) {
                // Stamp the cadence only on a real acceptance, so a failing
                // server does not silently stretch the interval.
                Snapshot::markUploaded();
                Telemetry::noteUploadAccepted();
                // Same honesty read as flushSpans: the reply may name rows the
                // server refused. Body already in hand; self-guarded.
                Telemetry::noteServerDrops(is_array($res['body']) ? $res['body'] : null);

                return true;
            }
            Telemetry::noteUploadRejected(
                $status === 0 ? 'unreachable'
                    : (($status === 401 || $status === 403) ? 'unauthorized'
                        : ($status >= 500 ? 'server-error' : 'rejected'))
            );

            return false;
        }, false);
    }

    /** Both gates, in order: entitlement first, then the sharing switch. */
    public static function canUpload(): bool
    {
        if (RuntimeFlags::disabled()) {
            return false;
        }
        if (!KillSwitch::isActive()) {
            return false;
        }
        if (!Telemetry::isEnabled() || !Telemetry::isRegistered() || Telemetry::disconnected()) {
            return false;
        }

        return Telemetry::fullTelemetry();
    }

    /**
     * A missing first entitlement answer may retain rows, but every other gate
     * remains decisive. This never grants permission to send.
     */
    public static function canRetainSamplesWhileLocked(): bool
    {
        return !RuntimeFlags::disabled()
            && KillSwitch::awaitingFirstAnswer()
            && Telemetry::isEnabled()
            && Telemetry::isRegistered()
            && !Telemetry::disconnected()
            && Telemetry::fullTelemetry();
    }
}
