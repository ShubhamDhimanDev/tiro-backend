<?php

use App\Services\Notifications\MessageMediaClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * `App\Services\Notifications\MessageMediaClient` — the only
 * `App\Contracts\Notifications\SmsProvider` implementor this phase. Tested
 * entirely via `Http::fake()`, never a real call — see that class's
 * docblock (MessageMedia sandbox/developer-account access is unconfirmed on
 * the business side).
 */
beforeEach(function () {
    config([
        'services.messagemedia.api_key' => 'test-key',
        'services.messagemedia.api_secret' => 'test-secret',
        'services.messagemedia.base_url' => 'https://api.messagemedia.test/v1',
    ]);

    $this->client = new MessageMediaClient;
});

it('posts the message to the documented endpoint with basic auth and returns the provider message id', function () {
    Http::fake([
        'api.messagemedia.test/v1/messages' => Http::response([
            'messages' => [
                ['message_id' => 'msg-123', 'status' => 'queued'],
            ],
        ], 200),
    ]);

    $result = $this->client->send('+61491570156', 'Your booking is confirmed.');

    expect($result->success)->toBeTrue();
    expect($result->providerMessageId)->toBe('msg-123');
    expect($result->error)->toBeNull();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.messagemedia.test/v1/messages'
            && $request['messages'][0]['content'] === 'Your booking is confirmed.'
            && $request['messages'][0]['destination_number'] === '+61491570156'
            && $request->hasHeader('Authorization');
    });
});

it('returns a failed result on a non-successful response, without throwing', function () {
    Http::fake(['api.messagemedia.test/v1/messages' => Http::response(['message' => 'Unauthorized'], 401)]);

    $result = $this->client->send('+61491570156', 'Hello');

    expect($result->success)->toBeFalse();
    expect($result->providerMessageId)->toBeNull();
    expect($result->error)->toContain('401');
});

/**
 * Security review, Phase 7 item 1: MessageMedia's own request shape echoes
 * submitted `content`/`destination_number` back inside a `messages` array
 * on a per-message validation error — logging the raw response body
 * wholesale would risk writing actual SMS text into a plain log file. Only
 * a narrow, known-safe top-level `code`/`message` pair is ever logged.
 */
it('never logs the raw response body on failure, only a narrow status/code/message subset', function () {
    Log::spy();
    Http::fake(['api.messagemedia.test/v1/messages' => Http::response([
        'code' => 'BAD_REQUEST',
        'message' => 'Invalid destination number',
        'messages' => [['content' => 'this is the actual SMS text that must never be logged', 'destination_number' => '+61491570156']],
    ], 400)]);

    $this->client->send('+61491570156', 'this is the actual SMS text that must never be logged');

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $context === [
        'status' => 400,
        'error_code' => 'BAD_REQUEST',
        'error_message' => 'Invalid destination number',
    ]);
});
