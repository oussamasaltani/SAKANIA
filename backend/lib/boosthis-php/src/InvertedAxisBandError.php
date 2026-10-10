<?php

declare(strict_types=1);

namespace Boosthis;

/** A reversed or non-finite pair of axis-band constants in the kit's source. */
final class InvertedAxisBandError extends \InvalidArgumentException
{
    public const INVERTED_BAND_MESSAGE = 'boosthis: inverted axis band, good must be below poor';

    public function __construct(
        public readonly float $good,
        public readonly float $poor
    ) {
        parent::__construct(self::INVERTED_BAND_MESSAGE . " (good=$good, poor=$poor)");
    }
}