<?php

namespace App\Http\Controllers\Api\V1\SocialProof;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Http\Controllers\Controller;
use App\Http\Resources\SocialProofOrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Public, read-only "recently purchased" social-proof feed, built only from
 * real paid orders — see docs/architecture/02-api-contract.md's "Social
 * proof: recent orders (R2)". Exposes first name, suburb/state, product label
 * and an hour-rounded timestamp only; never surname, contact details, street,
 * postcode, order identifiers or amounts. This is data minimisation, not
 * anonymity: a first name plus suburb can still identify someone locally, which
 * is why the feature ships behind `social_proof.enabled` (default false) and
 * must stay off until a settable consent mechanism exists.
 */
class RecentOrderController extends Controller
{
    public const CACHE_KEY = 'social-proof:recent-orders';

    private const CACHE_SECONDS = 60;

    private const WINDOW_DAYS = 7;

    private const MAX_ROWS = 10;

    /**
     * Candidate rows scanned before skipping unusable ones (no usable first
     * name / no line item), so a few skipped rows don't shrink the feed.
     */
    private const CANDIDATE_LIMIT = 50;

    private const MAX_FIRST_NAME_LENGTH = 20;

    private const HONORIFICS = ['mr', 'mrs', 'ms', 'miss', 'dr', 'prof'];

    private const BUSINESS_WORDS = ['pty', 'ltd', 'group', 'tyres', 'motors'];

    public function index(): JsonResponse
    {
        if (! config('social_proof.enabled')) {
            return $this->respond([]);
        }

        $rows = Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => $this->buildRows());

        return $this->respond($rows);
    }

    /**
     * @param  list<array{first_name: string, suburb: string, state: string, product_label: string, purchased_at: string}>  $rows
     */
    private function respond(array $rows): JsonResponse
    {
        return SocialProofOrderResource::collection($rows)
            ->response()
            ->header('Cache-Control', 'public, max-age='.self::CACHE_SECONDS);
    }

    /**
     * @return list<array{first_name: string, suburb: string, state: string, product_label: string, purchased_at: string}>
     */
    private function buildRows(): array
    {
        $orders = Order::query()
            ->where('payment_status', PaymentStatus::Paid)
            ->whereIn('status', [OrderStatus::Confirmed, OrderStatus::Completed])
            ->whereDoesntHave('payments', fn ($query) => $query->where('type', PaymentType::Refund))
            ->where('hide_from_social_proof', false)
            ->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->whereNotNull('customer_id')
            ->with([
                'customer:id,name',
                'address:id,suburb_id',
                'address.suburb:id,name,state_id',
                'address.suburb.state:id,code',
                'lineItems' => fn ($query) => $query->orderBy('id'),
                'lineItems.tyreVariant:id,tyre_model_id,width,profile,rim_diameter',
                'lineItems.tyreVariant.tyreModel:id,brand_id,name',
                'lineItems.tyreVariant.tyreModel.brand:id,name',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::CANDIDATE_LIMIT)
            ->get();

        $rows = [];
        $seenCustomers = [];

        foreach ($orders as $order) {
            $row = $this->toRow($order);

            if ($row === null || isset($seenCustomers[$order->customer_id])) {
                continue;
            }

            $seenCustomers[$order->customer_id] = true;
            $rows[] = $row;
        }

        if (count($seenCustomers) < (int) config('social_proof.min_orders')) {
            return [];
        }

        return array_slice($rows, 0, self::MAX_ROWS);
    }

    /**
     * @return array{first_name: string, suburb: string, state: string, product_label: string, purchased_at: string}|null
     */
    private function toRow(Order $order): ?array
    {
        $firstName = $this->firstName($order->customer?->name);
        $suburb = $order->address?->suburb;
        $state = $suburb?->state;
        $variant = $order->lineItems->first()?->tyreVariant;
        $tyreModel = $variant?->tyreModel;
        $brand = $tyreModel?->brand;

        if ($firstName === null || $suburb === null || $state === null || $variant === null || $tyreModel === null || $brand === null || $order->created_at === null) {
            return null;
        }

        return [
            'first_name' => $firstName,
            'suburb' => $suburb->name,
            'state' => $state->code,
            'product_label' => sprintf(
                '%s %s (%d/%d R%d)',
                $brand->name,
                $tyreModel->name,
                $variant->width,
                $variant->profile,
                $variant->rim_diameter,
            ),
            'purchased_at' => $order->created_at->copy()->utc()->startOfHour()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * First whitespace-delimited token of the name, letters (plus apostrophe
     * and hyphen) only, capitalised and capped at 20 characters. Null (row
     * skipped) if the token is an honorific, a single initial, contains digits
     * or a business word, or nothing usable remains.
     */
    private function firstName(?string $name): ?string
    {
        $raw = preg_split('/\s+/u', trim((string) $name))[0] ?? '';
        $lower = Str::lower($raw);

        if (preg_match('/\d/', $raw) === 1 || in_array(rtrim($lower, '.'), self::HONORIFICS, true) || Str::contains($lower, self::BUSINESS_WORDS)) {
            return null;
        }

        $token = (string) preg_replace("/[^\\p{L}'\\-]/u", '', $raw);

        if (mb_strlen($token) < 2) {
            return null;
        }

        return Str::ucfirst(Str::lower(Str::limit($token, self::MAX_FIRST_NAME_LENGTH, '')));
    }
}
