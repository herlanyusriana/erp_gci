<?php

namespace Tests\Feature;

use App\Models\Machine;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MachineLabelTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@geumcheon.local')->firstOrFail();
    }

    public function test_machine_label_renders_qr_payload(): void
    {
        $machine = Machine::query()->firstOrFail();

        $this->actingAs($this->admin())
            ->get(route('machines.label', $machine))
            ->assertOk()
            ->assertSee($machine->machine_code)
            ->assertSee($machine->machine_name)
            ->assertSee('<svg', false);
    }

    public function test_resolve_machine_by_code(): void
    {
        $machine = Machine::query()->where('is_active', true)->firstOrFail();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/machines/resolve', ['machine_code' => strtolower($machine->machine_code)])
            ->assertOk()
            ->assertJsonPath('data.id', $machine->id)
            ->assertJsonPath('data.machine_code', $machine->machine_code);
    }

    public function test_resolve_machine_returns_not_found(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/machines/resolve', ['machine_code' => 'TIDAK-ADA-MESIN'])
            ->assertNotFound();
    }
}
