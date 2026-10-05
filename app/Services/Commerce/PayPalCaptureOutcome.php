<?php

namespace App\Services\Commerce;

/**
 * The result of {@see PayPalCaptureService::captureAndConfirm()} — lets
 * `OrderController::paypalCapture()` distinguish "captured and confirmed"
 * (`200` with the updated order payload) from "PayPal declined the capture"
 * (`422`) without the controller needing to inspect exception types itself.
 */
final class PayPalCaptureOutcome
{
    private function __construct(
        public readonly bool $succeeded,
        public readonly ?string $declineReason,
    ) {}

    public static function succeeded(): self
    {
        return new self(true, null);
    }

    public static function declined(string $reason): self
    {
        return new self(false, $reason);
    }
}
