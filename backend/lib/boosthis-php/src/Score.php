<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Composite score computation — identical formula to the Java, Python, Go and
 * Node runtimes:
 *
 *   score = TTFF x 0.25 + TTI x 0.45 + FID x 0.30
 *
 * good >= 85 · needs-work >= 60 · poor < 60.
 *
 * The poor boundary is INCLUSIVE for single-duration ratings
 * (ms >= poor -> "poor").
 *
 * Nothing else may touch this score. Every additive axis (infrastructure
 * meters, security-exposure meters, learned meters) is display-only by
 * contract — see the cross-runtime "additive meters" rule.
 */
final class Score
{
    private function __construct()
    {
    }

    public const GOOD = 'good';
    public const NEEDS_WORK = 'needs-work';
    public const POOR = 'poor';

    /**
     * The three silences.
     *
     * 'pending' used to be the ONLY word for "no score", and three genuinely
     * different states were all reported with it:
     *
     *   pending        still warming up — not enough data yet, a score IS
     *                  coming.
     *   NOT_AVAILABLE  this host cannot take the reading, so no score will
     *                  ever appear on THIS install (another host may score it
     *                  fine).
     *   NOT_SCORED     a real reading that is deliberately never graded, by
     *                  design, permanently, on every install. Refusal honesty
     *                  is the clearest case: header presence cannot tell a
     *                  wrongly refused caller from a correctly refused one, so
     *                  it reports what it saw and never grades it.
     *
     * A reader who could only see 'pending' read a permanent observation as a
     * wait and came back for a verdict that was never coming.
     */
    public const NOT_AVAILABLE = 'not-available';
    public const NOT_SCORED = 'not-scored';

    /**
     * A band is a pair of constants in our own source: $good scores 100 at or
     * below it, $poor scores 0 at or above it, with linear interpolation between.
     * Equal, reversed, or non-finite ends are a typo in the kit, never something
     * the customer's app did. This used to silently answer 100, hiding a meter
     * wired backwards behind a perfect verdict on every install.
     *
     * The throw is the BACKSTOP: scripts' axis-band-inversion gate reads the
     * declared constants out of every shipped kit and fails the release before
     * an inverted pair can reach anybody.
     */
    public static function scoreMetric(float $value, float $good, float $poor): int
    {
        if (!is_finite($good) || !is_finite($poor) || $poor <= $good) {
            throw new InvertedAxisBandError($good, $poor);
        }
        if ($value <= $good) {
            return 100;
        }
        if ($value >= $poor) {
            return 0;
        }

        return (int) round(100 * (1 - ($value - $good) / ($poor - $good)));
    }

    /**
     * Compute the composite score from raw metrics. A null metric is treated as
     * "not measured, assume good" (full 100), matching the other runtimes.
     */
    public static function computeScore(?float $ttffMs, ?float $ttiMs, ?float $fidMs): int
    {
        $ttff = $ttffMs !== null
            ? self::scoreMetric($ttffMs, Thresholds::TTFF_GOOD, Thresholds::TTFF_POOR)
            : 100;
        $tti = $ttiMs !== null
            ? self::scoreMetric($ttiMs, Thresholds::TTI_GOOD, Thresholds::TTI_POOR)
            : 100;
        $fid = $fidMs !== null
            ? self::scoreMetric($fidMs, Thresholds::FID_GOOD, Thresholds::FID_POOR)
            : 100;

        return (int) round(
            $ttff * Thresholds::TTFF_WEIGHT
            + $tti * Thresholds::TTI_WEIGHT
            + $fid * Thresholds::FID_WEIGHT
        );
    }

    /** Rating from a composite 0-100 score. */
    public static function getRating(int $score): string
    {
        if ($score >= Thresholds::COMPOSITE_GOOD) {
            return self::GOOD;
        }
        if ($score >= Thresholds::COMPOSITE_NEEDS_WORK) {
            return self::NEEDS_WORK;
        }

        return self::POOR;
    }

    /**
     * Rate a single duration against the shared TTI band. The poor boundary is
     * INCLUSIVE (ms >= poor -> "poor"), byte-identical to the RN / Node /
     * Python / Java / Go span emitters so a duration maps to the SAME enum in
     * every layer at the band edge.
     */
    public static function getDurationRating(float $ms): string
    {
        if ($ms <= Thresholds::TTI_GOOD) {
            return self::GOOD;
        }
        if ($ms >= Thresholds::TTI_POOR) {
            return self::POOR;
        }

        return self::NEEDS_WORK;
    }
}
