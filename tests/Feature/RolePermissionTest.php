<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\PartType;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use DatabaseTransactions;

    /** Peta role -> email user demo (super-admin memakai akun admin). */
    private const EMAILS = [
        'super-admin' => 'admin@geumcheon.local',
        'it-admin' => 'it-admin@geumcheon.local',
        'management' => 'management@geumcheon.local',
        'ppic' => 'ppic@geumcheon.local',
        'purchasing' => 'purchasing@geumcheon.local',
        'warehouse' => 'warehouse@geumcheon.local',
        'production' => 'production@geumcheon.local',
        'qc' => 'qc@geumcheon.local',
        'engineering' => 'engineering@geumcheon.local',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function userFor(string $role): User
    {
        return User::where('email', self::EMAILS[$role])->firstOrFail();
    }

    public function test_every_role_has_an_expected_part_create_ability(): void
    {
        $expected = [
            'super-admin' => true,
            'management' => true,
            'engineering' => true,
            'it-admin' => false,
            'ppic' => false,
            'purchasing' => false,
            'warehouse' => false,
            'production' => false,
            'qc' => false,
        ];

        foreach ($expected as $role => $allowed) {
            $user = $this->userFor($role);
            $actual = Gate::forUser($user)->allows('create', Part::class);

            $this->assertSame(
                $allowed,
                $actual,
                "Role {$role}: create part seharusnya ".($allowed ? 'boleh' : 'ditolak').'.',
            );
        }
    }

    public function test_management_can_write_a_part(): void
    {
        $this->actingAs($this->userFor('management'))
            ->post(route('parts.store'), [
                'part_number' => 'MGMT-TEST-001',
                'part_name' => 'Management Test Part',
                'part_type_id' => PartType::firstOrFail()->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('parts', ['part_number' => 'MGMT-TEST-001']);
    }

    public function test_roles_without_part_create_are_forbidden(): void
    {
        foreach (['it-admin', 'ppic', 'purchasing', 'warehouse', 'production', 'qc'] as $role) {
            $this->actingAs($this->userFor($role))
                ->post(route('parts.store'), [
                    'part_number' => 'DENIED-'.$role,
                    'part_name' => 'Denied',
                    'part_type_id' => PartType::firstOrFail()->id,
                ])
                ->assertForbidden();
        }
    }

    public function test_only_it_admin_and_super_admin_can_manage_users(): void
    {
        $payload = [
            'name' => 'New Operator',
            'email' => 'new-operator@geumcheon.local',
            'password' => 'password',
            'password_confirmation' => 'password',
        ];

        $this->actingAs($this->userFor('it-admin'))
            ->post(route('users.store'), $payload)
            ->assertRedirect();
        $this->assertDatabaseHas('users', ['email' => 'new-operator@geumcheon.local']);

        $this->actingAs($this->userFor('management'))
            ->post(route('users.store'), [
                ...$payload,
                'email' => 'blocked@geumcheon.local',
            ])
            ->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'blocked@geumcheon.local']);
    }

    /**
     * Task 09: PPIC memegang `delivery.view/create/update/delete` tetapi tidak
     * memegang `stock.issue`. Gate tile Outgoing harus menerima izin delivery,
     * bukan hanya `stock.issue`.
     */
    public function test_ppic_qualifies_for_the_outgoing_tile_and_can_open_it(): void
    {
        $ppic = $this->userFor('ppic');

        $this->assertFalse($ppic->hasPermission('stock.issue'));
        $this->assertTrue(
            $ppic->hasAnyPermission(['stock.issue', 'delivery.view', 'delivery.create']),
            'PPIC harus lolos gate tile Outgoing lewat delivery.view.',
        );

        $this->actingAs($ppic)
            ->get(route('launcher'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Launcher')
                ->where('auth.permissions', fn ($permissions) => $permissions->contains('delivery.view')));

        $this->actingAs($ppic)
            ->get(route('outgoing-data'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Outgoing/ModuleLauncher'));
    }

    public function test_user_without_outgoing_permissions_does_not_qualify_for_the_tile(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(
            $user->hasAnyPermission(['stock.issue', 'delivery.view', 'delivery.create']),
            'User tanpa izin Outgoing tidak boleh lolos gate tile.',
        );

        $this->actingAs($user)
            ->get(route('launcher'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Launcher')
                ->where('auth.permissions', fn ($permissions) => $permissions
                    ->intersect(['stock.issue', 'delivery.view', 'delivery.create'])
                    ->isEmpty()));
    }

    /**
     * Tidak ada test runner JS di repo ini, jadi guard ini memeriksa sumber
     * komponen langsung agar gate tile Outgoing tidak kembali ke `stock.issue`.
     */
    public function test_outgoing_tile_gate_includes_delivery_permissions(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Launcher.vue'));

        $this->assertStringContainsString(
            "permission: ['stock.issue', 'delivery.view', 'delivery.create']",
            $source,
            'Tile Outgoing harus lolos untuk pemegang delivery.view (mis. PPIC).',
        );
    }
}
