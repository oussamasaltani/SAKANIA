<?php

declare(strict_types=1);

namespace Boosthis;

/**
 * Raised when a payload contains a denylisted field name or a PII-looking
 * value. The kit catches this internally and drops the payload — it must never
 * escape into the host application.
 */
final class PiiDetectedException extends \RuntimeException
{
    public function __construct(
        public readonly string $path,
        public readonly string $fieldName,
        public readonly string $matchedFragment
    ) {
        parent::__construct(
            'Boosthis no-PII guard refused outgoing payload: field "' . $path
            . '" (name "' . $fieldName . '") matches denied fragment "'
            . $matchedFragment . '". Boosthis\'s privacy contract forbids '
            . 'transmitting personally identifying data. If this field is '
            . 'genuinely non-PII, rename it; otherwise remove it.'
        );
    }
}
