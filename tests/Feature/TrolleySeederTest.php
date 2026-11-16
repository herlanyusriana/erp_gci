<?php

namespace Tests\Feature;

use App\Models\Trolley;
use Database\Seeders\TrolleySeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TrolleySeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_seeder_loads_the_snapshot_and_is_idempotent(): void
    {
        Trolley::query()->delete();

        $this->seed(TrolleySeeder::class);

        $first = Trolley::count();
        $this->assertSame(202, $first, 'Snapshot master troli harus terisi 202 baris.');

        // Dijalankan dua kali tidak menggandakan baris.
        $this->seed(TrolleySeeder::class);
        $this->assertSame($first, Trolley::count());

        // Hanya identitas troli: tidak ada kolom status.
        $this->assertFalse(
            Schema::hasColumn('trolleys', 'status'),
            'Status in/out bukan bagian master troli.',
        );

        $first = Trolley::query()->orderBy('code')->first();
        $this->assertNotNull($first->code);
        $this->assertContains($first->type, ['internal', 'external']);
    }
}
