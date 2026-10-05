<?php

namespace App\Http\Controllers\Api\V1\Enquiries;

use App\Enums\EnquiryStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Enquiries\StoreEnquiryRequest;
use App\Mail\EnquiryReceivedMail;
use App\Models\Enquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * `POST /api/v1/enquiries` — public, unauthenticated, throttled per IP
 * (`throttle:enquiries`). Stores the enquiry and queues an internal
 * notification. Only the customer-facing `reference` is ever returned —
 * never the row id.
 */
class EnquiryController extends Controller
{
    public function store(StoreEnquiryRequest $request): JsonResponse
    {
        // Honeypot: a filled hidden field means a bot. Answer exactly like a
        // success (so it learns nothing) but store and send nothing.
        if (filled($request->input('website'))) {
            return $this->accepted('ENQ-'.strtoupper(Str::random(8)), (string) $request->input('type'));
        }

        $enquiry = Enquiry::create([
            ...$request->safe()->except('website'),
            'reference' => Enquiry::generateReference(),
            'status' => EnquiryStatus::New,
            'ip_address' => $request->ip(),
        ]);

        $recipient = config('mail.enquiries_to');

        if (is_string($recipient) && $recipient !== '') {
            Mail::to($recipient)->queue(new EnquiryReceivedMail($enquiry));
        }

        return $this->accepted($enquiry->reference, $enquiry->type->value);
    }

    private function accepted(string $reference, string $type): JsonResponse
    {
        return response()->json(['data' => [
            'reference' => $reference,
            'type' => $type,
            'message' => "Thanks, we've got your message. We'll be in touch soon.",
        ]], 201);
    }
}
