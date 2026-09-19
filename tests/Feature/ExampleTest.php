<?php

use App\Models\User;

it('redirects guests from home to login', function () {
    $this->get('/')->assertRedirect(route('login'));
});

it('redirects authenticated users from home to launcher', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertRedirect(route('launcher'));
});

it('does not expose public registration', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();
});
