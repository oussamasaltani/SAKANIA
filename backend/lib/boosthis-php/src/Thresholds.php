<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Shared scoring thresholds — byte-identical to Java's Thresholds.java,
 * Python's thresholds.py and Node's thresholds.ts. There is exactly one source
 * of these numbers per runtime.
 *
 * Per-metric good/poor bounds and composite weights:
 *   TTFF: good <= 300ms, poor >= 800ms,  weight 0.25
 *   TTI:  good <= 500ms, poor >= 1500ms, weight 0.45
 *   FID:  good <= 50ms,  poor >= 150ms,  weight 0.30
 *
 * Composite rating: good >= 85, needs-work >= 60, else poor.
 * The poor boundary is INCLUSIVE (>=) for every metric.
 */
final class Thresholds
{
    private function __construct()
    {
    }

    /**
     * Runtime version string. This is the kit's OWN version and the single
     * source the hosted MCP compares an install against — bump it in lock-step
     * with composer.json and the KIT_CHANGELOG, never on its own.
     */
    public const RUNTIME_VERSION = '1.0.70';

    public const TTFF_GOOD = 300;
    public const TTFF_POOR = 800;
    public const TTFF_WEIGHT = 0.25;

    public const TTI_GOOD = 500;
    public const TTI_POOR = 1500;
    public const TTI_WEIGHT = 0.45;

    public const FID_GOOD = 50;
    public const FID_POOR = 150;
    public const FID_WEIGHT = 0.30;

    /** Ignores tails whose p99 is too small to be actionable. */
    public const RESILIENCE_TAIL_FLOOR_MS = 50;
    /** Requires enough observations for p99 to mean more than a handful's maximum. */
    public const RESILIENCE_MIN_TAIL_SAMPLES = 20;
    /** Treats medians below millisecond capture resolution as unmeasured. */
    public const RESILIENCE_MIN_MEDIAN_MS = 1;

    /** Composite score at or above this is rated "good". */
    public const COMPOSITE_GOOD = 85;
    /** Composite score at or above this (and below good) is "needs-work". */
    public const COMPOSITE_NEEDS_WORK = 60;
}
