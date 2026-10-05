<?php

use App\Enums\EnquiryStatus;
use App\Enums\EnquiryType;
use App\Mail\EnquiryReceivedMail;
use App\Models\Enquiry;
use Illuminate\Support\Facades\Mail;

/**
 * `POST /api/v1/enquiries` — contact / quote / fleet / out_of_area.
 */
beforeEach(function () {
    Mail::fake();
    config(['mail.enquiries_to' => 'ops@tiro.test']);
});

function contactPayload(array $overrides = []): array
{
    return array_merge([
        'type' => 'contact',
        'name' => 'Jane Citizen',
        'email' => 'Jane@Example.com',
        'phone' => '0412 345 678',
        'message' => 'Do you fit run-flat tyres?',
    ], $overrides);
}

it('stores a contact enquiry, returns only a reference and queues the notification', function () {
    $response = $this->postJson('/api/v1/enquiries', contactPayload());

    $response->assertCreated()->assertJsonStructure(['data' => ['reference', 'type', 'message']]);
    expect($response->json('data.type'))->toBe('contact')
        ->and($response->json('data.reference'))->toStartWith('ENQ-')
        ->and($response->json('data'))->not->toHaveKey('id');

    $enquiry = Enquiry::query()->firstOrFail();
    expect($enquiry->reference)->toBe($response->json('data.reference'))
        ->and($enquiry->type)->toBe(EnquiryType::Contact)
        ->and($enquiry->status)->toBe(EnquiryStatus::New)
        ->and($enquiry->email)->toBe('jane@example.com')
        ->and($enquiry->name)->toBe('Jane Citizen')
        ->and($enquiry->ip_address)->not->toBeNull();

    Mail::assertQueued(EnquiryReceivedMail::class, fn (EnquiryReceivedMail $mail) => $mail->hasTo('ops@tiro.test')
        && $mail->hasReplyTo('jane@example.com')
        && $mail->enquiry->is($enquiry));
});

it('stores a quote enquiry with a tyre size and rego/state', function () {
    $this->postJson('/api/v1/enquiries', [
        'type' => 'quote', 'name' => 'Sam', 'email' => 'sam@example.com', 'phone' => '0400000000',
        'tyre_size' => '205/55R16', 'rego' => 'abc-123', 'rego_state' => 'vic', 'suburb' => 'Richmond', 'postcode' => '3121',
        'message' => 'Need 4 tyres.',
    ])->assertCreated();

    $enquiry = Enquiry::query()->firstOrFail();
    expect($enquiry->type)->toBe(EnquiryType::Quote)
        ->and($enquiry->tyre_size)->toBe('205/55R16')
        ->and($enquiry->rego)->toBe('ABC123')
        ->and($enquiry->rego_state)->toBe('VIC')
        ->and($enquiry->suburb)->toBe('Richmond')
        ->and($enquiry->postcode)->toBe('3121');
});

it('stores a fleet enquiry with company and fleet size', function () {
    $this->postJson('/api/v1/enquiries', [
        'type' => 'fleet', 'name' => 'Pat', 'email' => 'pat@acme.test', 'phone' => '0400000000',
        'company' => 'Acme Couriers', 'fleet_size' => 42,
    ])->assertCreated();

    expect(Enquiry::query()->firstOrFail())
        ->company->toBe('Acme Couriers')
        ->fleet_size->toBe(42)
        ->type->toBe(EnquiryType::Fleet);
});

it('stores an out-of-area notify-me sign-up with only a suburb or postcode', function () {
    $this->postJson('/api/v1/enquiries', ['type' => 'out_of_area', 'name' => 'Lee', 'email' => 'lee@example.com', 'postcode' => '6000'])->assertCreated();
    $this->postJson('/api/v1/enquiries', ['type' => 'out_of_area', 'name' => 'Lee', 'email' => 'lee@example.com', 'suburb' => 'Perth'])->assertCreated();

    expect(Enquiry::query()->where('type', EnquiryType::OutOfArea)->count())->toBe(2);
});

it('still stores the enquiry but sends no mail when no recipient is configured', function () {
    config(['mail.enquiries_to' => null]);

    $this->postJson('/api/v1/enquiries', contactPayload())->assertCreated();

    expect(Enquiry::query()->count())->toBe(1);
    Mail::assertNothingQueued();
});

it('rejects an unknown or missing type with a clear message', function () {
    $this->postJson('/api/v1/enquiries', contactPayload(['type' => 'spam']))
        ->assertUnprocessable()->assertJsonValidationErrors(['type']);
    $this->postJson('/api/v1/enquiries', array_diff_key(contactPayload(), ['type' => 1]))
        ->assertUnprocessable()->assertJsonPath('errors.type.0', 'Please choose what your enquiry is about.');

    expect(Enquiry::query()->count())->toBe(0);
});

it('validates the common fields with friendly messages', function () {
    $response = $this->postJson('/api/v1/enquiries', ['type' => 'contact', 'email' => 'not-an-email', 'phone' => 'abc']);

    $response->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'phone', 'message']);
    expect($response->json('errors.name.0'))->toBe('Please tell us your name.')
        ->and($response->json('errors.email.0'))->toBe('That email address does not look right. Check it and try again.')
        ->and($response->json('errors.phone.0'))->toBe('Enter a valid phone number, for example 0412 345 678.')
        ->and($response->json('errors.message.0'))->toBe('Please tell us how we can help.');
});

it('requires phone and a tyre size or rego for a quote', function () {
    $response = $this->postJson('/api/v1/enquiries', ['type' => 'quote', 'name' => 'A', 'email' => 'a@example.com']);

    $response->assertUnprocessable()->assertJsonValidationErrors(['phone', 'tyre_size']);

    $this->postJson('/api/v1/enquiries', ['type' => 'quote', 'name' => 'A', 'email' => 'a@example.com', 'phone' => '0400000000', 'rego' => 'ABC123'])
        ->assertUnprocessable()->assertJsonValidationErrors(['rego_state']);
});

it('requires company and fleet size for a fleet enquiry', function () {
    $this->postJson('/api/v1/enquiries', ['type' => 'fleet', 'name' => 'A', 'email' => 'a@example.com', 'phone' => '0400000000'])
        ->assertUnprocessable()->assertJsonValidationErrors(['company', 'fleet_size']);
    $this->postJson('/api/v1/enquiries', ['type' => 'fleet', 'name' => 'A', 'email' => 'a@example.com', 'phone' => '0400000000', 'company' => 'X', 'fleet_size' => 0])
        ->assertUnprocessable()->assertJsonValidationErrors(['fleet_size']);
});

it('requires a suburb or postcode for out_of_area and a 4-digit postcode', function () {
    $this->postJson('/api/v1/enquiries', ['type' => 'out_of_area', 'name' => 'A', 'email' => 'a@example.com'])
        ->assertUnprocessable()->assertJsonValidationErrors(['suburb', 'postcode']);
    $this->postJson('/api/v1/enquiries', ['type' => 'out_of_area', 'name' => 'A', 'email' => 'a@example.com', 'postcode' => '12'])
        ->assertUnprocessable()->assertJsonValidationErrors(['postcode']);
});

it('rejects an over-long message and a bad state', function () {
    $this->postJson('/api/v1/enquiries', contactPayload(['message' => str_repeat('a', 3001)]))
        ->assertUnprocessable()->assertJsonValidationErrors(['message']);
    $this->postJson('/api/v1/enquiries', contactPayload(['rego' => 'ABC123', 'rego_state' => 'XX']))
        ->assertUnprocessable()->assertJsonValidationErrors(['rego_state']);
});

it('silently discards a honeypot submission: looks successful, stores and sends nothing', function () {
    $response = $this->postJson('/api/v1/enquiries', contactPayload(['website' => 'http://spam.example']));

    $response->assertCreated()->assertJsonStructure(['data' => ['reference', 'type', 'message']]);
    expect(Enquiry::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('throttles per IP: the sixth request inside a minute is 429', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/enquiries', contactPayload())->assertCreated();
    }

    $this->postJson('/api/v1/enquiries', contactPayload())->assertTooManyRequests();

    expect(Enquiry::query()->count())->toBe(5);
});

it('counts validation failures toward the throttle too', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/enquiries', [])->assertUnprocessable();
    }

    $this->postJson('/api/v1/enquiries', contactPayload())->assertTooManyRequests();
});

it('throttles per IP address, not globally', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->postJson('/api/v1/enquiries', contactPayload())->assertCreated();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->postJson('/api/v1/enquiries', contactPayload())->assertTooManyRequests();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->postJson('/api/v1/enquiries', contactPayload())->assertCreated();
});

it('escapes user-supplied content in the notification email', function () {
    $enquiry = Enquiry::factory()->create(['name' => '<script>alert(1)</script>', 'message' => '<b>hi</b>']);

    $html = (new EnquiryReceivedMail($enquiry))->render();

    expect($html)->not->toContain('<script>')->and($html)->toContain('&lt;script&gt;')->and($html)->not->toContain('<b>hi</b>');
});
