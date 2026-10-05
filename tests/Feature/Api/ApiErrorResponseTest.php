<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    config(['app.debug' => true]);
});

it('returns a friendly server_error body with no internals for unexpected exceptions', function (): void {
    Route::get('/api/_boom', fn () => throw new RuntimeException('SQLSTATE[HY000] [2002] tcp://127.0.0.1:6379 secret'));

    $response = $this->getJson('/api/_boom');

    $response->assertStatus(500)
        ->assertExactJson([
            'message' => 'Something went wrong on our side. Please try again in a moment.',
            'code' => 'server_error',
        ]);
    expect($response->getContent())->not->toContain('SQLSTATE')->not->toContain('RuntimeException');
});

it('returns not_found for unknown api routes and missing models', function (): void {
    $this->getJson('/api/v1/does-not-exist')
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');
});

it('returns 405 method_not_allowed', function (): void {
    Route::get('/api/_only-get', fn () => ['ok' => true]);

    $this->postJson('/api/_only-get')->assertStatus(405)->assertJsonPath('code', 'method_not_allowed');
});

it('keeps validation errors with a code and errors map', function (): void {
    Route::post('/api/_validate', fn (Request $r) => $r->validate(['name' => 'required']));

    $this->postJson('/api/_validate', [])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors(['name']);
});

it('returns unauthenticated for protected routes', function (): void {
    Route::get('/api/_private', fn () => 'x')->middleware('auth:sanctum');

    $this->getJson('/api/_private')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
});
