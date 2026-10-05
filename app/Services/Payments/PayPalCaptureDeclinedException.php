<?php

namespace App\Services\Payments;

use PaypalServerSdkLib\Exceptions\ErrorException;
use RuntimeException;

/**
 * A PayPal-reported decline/failure on a capture attempt — distinct from an
 * unexpected SDK/network error, which propagates uncaught (a genuine `500`,
 * same posture as every other unexpected failure in this codebase). Caught
 * by `App\Services\Commerce\PayPalCaptureService`, which maps it onto
 * `Payment.status = failed`/`Order.status = payment_failed` and a `422`
 * response — see docs/architecture/02-api-contract.md's
 * `POST /api/v1/orders/{order}/paypal-capture` section, step 5.
 */
final class PayPalCaptureDeclinedException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $rawResponse
     */
    public function __construct(string $reason, public readonly array $rawResponse = [])
    {
        parent::__construct("PayPal declined the capture: {$reason}");
    }

    /**
     * PayPal's Capture API rejected the request outright (e.g. `422
     * UNPROCESSABLE_ENTITY` with an `INSTRUMENT_DECLINED`/`ORDER_ALREADY_CAPTURED`
     * issue) — the SDK surfaces this as a thrown `ErrorException`, not a
     * normal response.
     */
    public static function fromApiError(ErrorException $e): self
    {
        $issues = array_values(array_filter(array_map(
            fn ($detail): string => $detail->getIssue(),
            $e->getDetails() ?? [],
        )));

        return new self("{$e->getName()} — {$e->getMessageProperty()}", [
            'status_code' => $e->getCode(),
            'name' => $e->getName(),
            'message' => $e->getMessageProperty(),
            'issues' => $issues,
        ]);
    }

    /**
     * PayPal accepted the capture call (`2xx`) but the capture's own
     * `status` field is something other than `COMPLETED` (e.g. `DECLINED`,
     * `PENDING`) — a business-level decline reported inline in the response
     * body rather than as an HTTP error.
     *
     * @param  array<string, mixed>  $rawResponse
     */
    public static function fromCaptureResource(array $rawResponse, string $status): self
    {
        return new self("capture status \"{$status}\" was not COMPLETED", $rawResponse);
    }
}
