<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LauncherTest extends TestCase
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

    public function test_launcher_renders(): void
    {
        $this->actingAs($this->admin())
            ->get(route('launcher'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Launcher'));
    }

    /**
     * Tile Inventory pernah memakai href '#' sehingga tidak bisa diklik walau
     * halaman stok sudah ada. Repo ini tidak punya test runner JS, jadi guard
     * ini memeriksa sumber komponen secara langsung.
     */
    public function test_inventory_tile_links_to_the_existing_stock_page(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Launcher.vue'));

        $this->assertStringContainsString(
            "href: '/stocks', title: t('account.inventory')",
            $source,
            'Tile Inventory harus menuju halaman stok yang sudah ada.',
        );

        $this->assertStringNotContainsString(
            "href: '#', title: t('account.inventory')",
            $source,
            'Tile Inventory tidak boleh memakai placeholder href.',
        );

        $this->assertTrue(
            Route::has('stocks.index'),
            'Route stok yang dituju tile Inventory harus terdaftar.',
        );
    }

    public function test_stock_page_is_reachable_from_the_launcher_route(): void
    {
        $this->actingAs($this->admin())
            ->get(route('stocks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Incoming/Stock/Index'));
    }
}
