<?php

/**
 * Task-breakdown Phase 0 test-coverage row: "Fortify registration-disabled
 * check — confirm the public registration route genuinely doesn't work."
 *
 * `Features::registration()` is removed from config/fortify.php's features
 * array, so Fortify never registers `/register` at all. The default Fortify
 * RegistrationTest.php skips itself entirely in that case
 * (`skipUnlessFortifyHas`), which proves nothing positive — this test hits
 * the raw path directly (bypassing the `route()` helper, which would itself
 * throw if the named route doesn't exist) to assert the route is genuinely
 * gone, not merely untested.
 */
it('does not register a GET /register route', function () {
    $this->get('/register')->assertNotFound();
});

it('does not register a POST /register route', function () {
    $this->post('/register', [
        'name' => 'Someone',
        'email' => 'someone@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertDatabaseMissing('users', ['email' => 'someone@example.com']);
});
