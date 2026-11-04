<?php

use App\Models\User;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('launcher', absolute: false));
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/');
});

test('apk_only users are blocked from web login', function () {
    $user = User::factory()->create([
        'email' => 'suharja.test@geumcheon.local',
        'password' => bcrypt('gci2026'),
        'apk_only' => true,
    ]);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'gci2026',
    ]);

    $this->assertGuest();
    $response->assertSessionHasErrors('email');
});

test('apk_only users can login to mobile api using short username or full email', function () {
    $this->postJson('/api/auth/login', [
        'email' => 'suharja',
        'password' => 'gci2026',
    ])->assertOk()->assertJsonPath('user.name', 'SUHARJA');

    $this->postJson('/api/auth/login', [
        'email' => 'teguh.heru.susanto@geumcheon.local',
        'password' => 'gci2026',
    ])->assertOk()->assertJsonPath('user.name', 'TEGUH HERU SUSANTO');
});
