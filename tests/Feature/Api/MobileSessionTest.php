<?php

namespace Tests\Feature\Api;

use App\Models\IncomingArrival;
use App\Models\IncomingArrivalItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sesi APK: satu perangkat = satu token hidup, dan batas peran gudang/produksi.
 */
class MobileSessionTest extends TestCase
{
    use DatabaseTransactions;

    private const DEVICE = 'material-tracker-mobile';

    private function tokenCountFor(User $user, string $device): int
    {
        return DB::table('personal_access_tokens')
            ->where('tokenable_id', $user->id)
            ->where('name', $device)
            ->count();
    }

    public function test_repeated_login_on_the_same_device_keeps_only_one_token(): void
    {
        $user = User::where('email', 'production@geumcheon.local')->firstOrFail();

        $first = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ], ['X-Device-Name' => self::DEVICE])->assertOk()->json('token');

        $second = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ], ['X-Device-Name' => self::DEVICE])->assertOk()->json('token');

        $this->assertNotSame($first, $second);
        $this->assertSame(1, $this->tokenCountFor($user, self::DEVICE));

        // Token lama harus mati: HP yang di-reinstall / berpindah operator
        // tidak boleh tetap punya akses setelah login ulang.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$first)
            ->getJson('/api/user')
            ->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$second)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('id', $user->id);
    }

    public function test_a_second_device_keeps_its_own_session(): void
    {
        $user = User::where('email', 'production@geumcheon.local')->firstOrFail();

        $phone = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ], ['X-Device-Name' => 'hp-operator'])->assertOk()->json('token');

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ], ['X-Device-Name' => 'tablet-lini'])->assertOk();

        $this->assertSame(1, $this->tokenCountFor($user, 'hp-operator'));
        $this->assertSame(1, $this->tokenCountFor($user, 'tablet-lini'));

        // Login di tablet tidak boleh memutus sesi di HP operator lain.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$phone)
            ->getJson('/api/user')
            ->assertOk();
    }

    public function test_production_operator_cannot_read_or_receive_warehouse_arrivals(): void
    {
        $operator = User::where('email', 'production@geumcheon.local')->firstOrFail();

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/incoming/departures')
            ->assertForbidden();

        // Baris nyata: route-model binding jalan lebih dulu, jadi id palsu
        // hanya menghasilkan 404 dan guard peran tidak pernah diuji.
        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARR-BOUND-'.uniqid(),
            'invoice_no' => 'INV-BOUND',
        ]);
        $item = IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'qty_goods' => 10,
            'unit_goods' => 'KGM',
        ]);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/incoming/arrival-items/{$item->id}/receive", [
                'receive_date' => now()->toDateString(),
                'tag' => 'TEST-TAG',
                'qty' => 1,
                'qc_status' => 'pass',
            ])
            ->assertForbidden();
    }

    public function test_warehouse_can_read_arrivals(): void
    {
        $warehouse = User::where('email', 'warehouse@geumcheon.local')->firstOrFail();

        $this->actingAs($warehouse, 'sanctum')
            ->getJson('/api/incoming/departures')
            ->assertOk();
    }

    public function test_purchasing_and_qc_keep_their_receiving_access(): void
    {
        // Policy `receive` menerima receive.create ATAU incoming.update; guard
        // route harus memakai aturan yang sama agar purchasing/QC tidak ikut
        // terkunci saat perbaikan batas peran dipasang.
        foreach (['purchasing@geumcheon.local', 'qc@geumcheon.local'] as $email) {
            $user = User::where('email', $email)->firstOrFail();

            $this->actingAs($user, 'sanctum')
                ->getJson('/api/incoming/departures')
                ->assertOk();
        }
    }
}
