<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Address;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderLineItem;
use App\Models\Payment;
use App\Models\State;
use App\Models\Suburb;
use App\Models\TyreModel;
use App\Models\TyreVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    config(['social_proof.enabled' => true]);
});

/**
 * Create a real order with customer, fitting address and one line item.
 *
 * @param  array<string, mixed>  $attributes
 */
function socialProofOrder(array $attributes = [], string $customerName = 'Joshua Smith'): Order
{
    $customer = Customer::factory()->create(['name' => $customerName]);
    $state = State::query()->where('code', 'NSW')->first() ?? State::factory()->create(['code' => 'NSW']);
    $suburb = Suburb::factory()->create(['name' => 'Jordan Springs', 'state_id' => $state->id]);
    $address = Address::factory()->create(['customer_id' => $customer->id, 'suburb_id' => $suburb->id]);
    $brand = Brand::query()->where('name', 'Michelin')->first() ?? Brand::factory()->create(['name' => 'Michelin']);
    $model = TyreModel::factory()->create(['brand_id' => $brand->id, 'name' => 'Agilis 3']);
    $variant = TyreVariant::factory()->create(['tyre_model_id' => $model->id, 'width' => 185, 'profile' => 65, 'rim_diameter' => 14]);

    $order = Order::factory()->confirmed()->create([
        'customer_id' => $customer->id,
        'address_id' => $address->id,
        ...$attributes,
    ]);
    OrderLineItem::factory()->create(['order_id' => $order->id, 'tyre_variant_id' => $variant->id]);

    return $order;
}

function seedSocialProofOrders(int $count): void
{
    foreach (range(1, $count) as $i) {
        socialProofOrder(['created_at' => now()->subMinutes($i)]);
    }
}

it('returns an empty list below the minimum threshold', function () {
    seedSocialProofOrders(4);

    $this->getJson('/api/v1/social-proof/recent-orders')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

it('honours a configured threshold', function () {
    config(['social_proof.min_orders' => 2]);
    seedSocialProofOrders(2);

    $this->getJson('/api/v1/social-proof/recent-orders')->assertOk()->assertJsonCount(2, 'data');
});

it('returns rows at or above the threshold with the exact documented shape and cache header', function () {
    $placed = now()->subHours(2)->startOfSecond()->addMinutes(17);
    socialProofOrder(['created_at' => $placed]);
    seedSocialProofOrders(4);

    $response = $this->getJson('/api/v1/social-proof/recent-orders')
        ->assertOk()
        ->assertJsonCount(5, 'data');

    expect($response->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=60');

    $row = collect($response->json('data'))->firstWhere('purchased_at', $placed->utc()->startOfHour()->format('Y-m-d\TH:i:s\Z'));

    expect($row)->toBe([
        'first_name' => 'Joshua',
        'suburb' => 'Jordan Springs',
        'state' => 'NSW',
        'product_label' => 'Michelin Agilis 3 (185/65 R14)',
        'purchased_at' => $placed->utc()->startOfHour()->format('Y-m-d\TH:i:s\Z'),
    ]);
});

it('never exposes anything beyond the five documented keys or any PII', function () {
    seedSocialProofOrders(5);
    $order = Order::query()->firstOrFail();

    $response = $this->getJson('/api/v1/social-proof/recent-orders')->assertOk();

    foreach ($response->json('data') as $row) {
        expect(array_keys($row))->toBe(['first_name', 'suburb', 'state', 'product_label', 'purchased_at']);
    }

    $body = $response->getContent();
    $customer = $order->customer;

    expect($body)
        ->not->toContain('Smith')
        ->not->toContain((string) $customer?->email)
        ->not->toContain((string) $customer?->mobile)
        ->not->toContain($order->order_number)
        ->not->toContain((string) $order->address?->line1);
});

it('excludes unpaid, old and opted-out orders', function () {
    config(['social_proof.min_orders' => 1]);

    socialProofOrder(['created_at' => now()->subMinute()], 'Included Person');
    socialProofOrder(['payment_status' => PaymentStatus::Pending, 'created_at' => now()->subMinutes(2)], 'Pending Person');
    socialProofOrder(['payment_status' => PaymentStatus::Refunded, 'created_at' => now()->subMinutes(3)], 'Refunded Person');
    socialProofOrder(['created_at' => now()->subDays(8)], 'Old Person');
    socialProofOrder(['hide_from_social_proof' => true, 'created_at' => now()->subMinutes(4)], 'Hidden Person');

    $this->getJson('/api/v1/social-proof/recent-orders')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.first_name', 'Included');
});

it('returns at most 10 rows, newest first', function () {
    foreach (range(1, 12) as $i) {
        socialProofOrder(['created_at' => now()->subMinutes($i)]);
    }

    $times = collect($this->getJson('/api/v1/social-proof/recent-orders')->assertOk()->assertJsonCount(10, 'data')->json('data'))
        ->pluck('purchased_at')
        ->all();

    $sorted = $times;
    rsort($sorted);

    expect($times)->toBe($sorted);
});

it('skips rows without a usable first name and normalises and caps names', function () {
    config(['social_proof.min_orders' => 1]);

    socialProofOrder(['created_at' => now()->subMinutes(1)], '   ');
    socialProofOrder(['created_at' => now()->subMinutes(2)], '123 456');
    socialProofOrder(['created_at' => now()->subMinutes(3)], 'ANNA-LEE o\'brien');
    socialProofOrder(['created_at' => now()->subMinutes(4)], 'Abcdefghijklmnopqrstuvwxyz Smith');

    $names = collect($this->getJson('/api/v1/social-proof/recent-orders')->assertOk()->json('data'))->pluck('first_name')->all();

    expect($names)->toBe(['Anna-lee', 'Abcdefghijklmnopqrst']);
});

it('caches the result for the request window', function () {
    config(['social_proof.min_orders' => 1]);
    socialProofOrder();

    $this->getJson('/api/v1/social-proof/recent-orders')->assertJsonCount(1, 'data');

    socialProofOrder();

    $this->getJson('/api/v1/social-proof/recent-orders')->assertJsonCount(1, 'data');
});

it('returns an empty list without querying when the kill switch is off', function () {
    seedSocialProofOrders(6);
    config(['social_proof.enabled' => false]);

    DB::enableQueryLog();

    $this->getJson('/api/v1/social-proof/recent-orders')->assertOk()->assertExactJson(['data' => []]);

    expect(DB::getQueryLog())->toBeEmpty();
});

it('is disabled by default', function () {
    expect(require config_path('social_proof.php'))->toHaveKey('enabled', false);
})->skip(fn () => env('SOCIAL_PROOF_ENABLED') !== null, 'env override present');

it('excludes cancelled, refund-required and refunded orders even when payment_status is paid', function () {
    config(['social_proof.min_orders' => 1]);

    socialProofOrder(['created_at' => now()->subMinute()], 'Good Person');
    socialProofOrder(['status' => OrderStatus::Cancelled, 'created_at' => now()->subMinutes(2)], 'Cancelled Person');
    socialProofOrder(['status' => OrderStatus::RefundRequired, 'created_at' => now()->subMinutes(3)], 'Required Person');
    $refunded = socialProofOrder(['created_at' => now()->subMinutes(4)], 'Refunded Person');
    Payment::factory()->create(['order_id' => $refunded->id, 'type' => PaymentType::Refund]);

    $this->getJson('/api/v1/social-proof/recent-orders')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.first_name', 'Good');
});

it('skips honorifics, single initials, digits and business names', function () {
    config(['social_proof.min_orders' => 1]);

    foreach (['Mr Smith', 'Dr. Who', 'Prof X', 'Miss Jones', 'J Smith', 'Pty Holdings', 'Tyres Direct', 'Bob2 Smith', 'Smithgroup Pty', 'Motors Central'] as $i => $name) {
        socialProofOrder(['created_at' => now()->subMinutes($i + 1)], $name);
    }
    socialProofOrder(['created_at' => now()->subHour()], 'Maria Lopez');

    $names = collect($this->getJson('/api/v1/social-proof/recent-orders')->assertOk()->json('data'))->pluck('first_name')->all();

    expect($names)->toBe(['Maria']);
});

it('applies the threshold to distinct customers, not rows', function () {
    $order = socialProofOrder(['created_at' => now()->subMinute()]);

    foreach (range(2, 6) as $i) {
        $repeat = Order::factory()->confirmed()->create([
            'customer_id' => $order->customer_id,
            'address_id' => $order->address_id,
            'created_at' => now()->subMinutes($i),
        ]);
        OrderLineItem::factory()->create(['order_id' => $repeat->id, 'tyre_variant_id' => $order->lineItems()->firstOrFail()->tyre_variant_id]);
    }

    $this->getJson('/api/v1/social-proof/recent-orders')->assertOk()->assertExactJson(['data' => []]);
});
