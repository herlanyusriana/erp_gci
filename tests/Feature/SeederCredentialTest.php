<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\ProductionOperatorSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class SeederCredentialTest extends TestCase
{
    use DatabaseTransactions;

    private const ADMIN_EMAIL = 'admin@geumcheon.local';

    /** @var list<string> */
    private const DEMO_EMAILS = [
        'engineering@geumcheon.local',
        'it-admin@geumcheon.local',
        'management@geumcheon.local',
        'ppic@geumcheon.local',
        'purchasing@geumcheon.local',
        'warehouse@geumcheon.local',
        'production@geumcheon.local',
        'qc@geumcheon.local',
    ];

    public function test_testing_environment_keeps_the_seed_admin_and_demo_users(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::where('email', self::ADMIN_EMAIL)->firstOrFail();
        $this->assertTrue(Hash::check('password', $admin->password));

        foreach (self::DEMO_EMAILS as $email) {
            $user = User::where('email', $email)->first();
            $this->assertNotNull($user, "User demo {$email} harus tetap dibuat di testing.");
            $this->assertTrue(Hash::check('password', $user->password));
        }
    }

    public function test_reseeding_does_not_reset_an_existing_admin_password(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::where('email', self::ADMIN_EMAIL)->firstOrFail();
        $admin->password = 'rahasia-lain';
        $admin->save();

        $before = $admin->fresh()->password;

        $this->seed(RolesAndPermissionsSeeder::class);

        $after = $admin->fresh()->password;

        $this->assertSame($before, $after, 'Re-seed tidak boleh mengubah password admin yang sudah ada.');
        $this->assertTrue(Hash::check('rahasia-lain', $after));
    }

    public function test_demo_users_are_not_created_outside_local_and_testing(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        User::whereIn('email', self::DEMO_EMAILS)->forceDelete();

        $originalEnv = $this->app['env'];
        $this->app['env'] = 'production';
        config(['seed.allow_demo_users' => false]);

        try {
            $this->artisan('db:seed', [
                '--class' => RolesAndPermissionsSeeder::class,
                '--force' => true,
            ])->assertSuccessful();
        } finally {
            $this->app['env'] = $originalEnv;
        }

        foreach (self::DEMO_EMAILS as $email) {
            $this->assertDatabaseMissing('users', ['email' => $email]);
        }
    }

    public function test_operator_seeder_requires_a_password_outside_local_and_testing(): void
    {
        config(['seed.operator_password' => null]);

        $originalEnv = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('SEED_OPERATOR_PASSWORD');

            (new ProductionOperatorSeeder)->run();
        } finally {
            $this->app['env'] = $originalEnv;
        }
    }
}
