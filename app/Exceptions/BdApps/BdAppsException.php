<?php

namespace App\Exceptions\BdApps;

use RuntimeException;

/**
 * Thrown when the Robi BDApps gateway returns a non-success response.
 *
 * Carries the gateway's `statusCode` (e.g. `E1312`), `statusDetail`
 * (human-readable message), and the underlying HTTP status so callers
 * can distinguish transport failures (`httpStatus` is null/0) from
 * gateway-level rejections.
 *
 * Extends RuntimeException so it's a normal exception type that
 * bubbles through Laravel's normal error handling — but the structured
 * properties are what log lines / callers should consume.
 */
class BdAppsException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $statusCode = null,
        public readonly ?string $statusDetail = null,
        public readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
