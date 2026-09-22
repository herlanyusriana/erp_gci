<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\PartPrice;
use App\Models\PartSubstitute;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PartPriceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::where('email', 'admin@geumcheon.local')->firstOrFail());

        PartSubstitute::firstOrCreate([
            'part_id' => Part::where('part_number', '4000W4A003A')->value('id'),
            'substitute_part_id' => $this->part()->id,
            'supplier_id' => $this->supplier()->id,
        ], ['is_active' => true]);
    }

    private function supplier(): Supplier
    {
        return Supplier::query()->orderBy('id')->firstOrFail();
    }

    private function part(): Part
    {
        return Part::where('part_number', 'CBKG07256C')->firstOrFail();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'supplier_id' => $this->supplier()->id,
            'part_id' => $this->part()->id,
            'price' => 12500.5,
            'currency' => 'IDR',
            'valid_from' => '2026-09-01',
            'is_active' => true,
        ], $overrides);
    }

    public function test_index_page_loads(): void
    {
        $this->get(route('prices.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Master/Price/Index')
                ->has('prices')
                ->has('suppliers')
                ->has('parts'));
    }

    public function test_store_creates_price(): void
    {
        $this->post(route('prices.store'), $this->payload())
            ->assertRedirect(route('prices.index'));

        $this->assertDatabaseHas('part_prices', [
            'supplier_id' => $this->supplier()->id,
            'part_id' => $this->part()->id,
            'price' => 12500.5,
            'currency' => 'IDR',
            'valid_from' => '2026-09-01',
        ]);
    }

    public function test_same_supplier_part_and_date_is_rejected(): void
    {
        $this->post(route('prices.store'), $this->payload())->assertRedirect();

        $this->post(route('prices.store'), $this->payload(['price' => 13000]))
            ->assertSessionHasErrors('supplier_id');
    }

    public function test_store_rejects_part_not_mapped_to_supplier(): void
    {
        $supplier = Supplier::create([
            'supplier_code' => 'SUP-PRICE-GUARD',
            'supplier_name' => 'Price Guard Supplier',
            'is_active' => true,
        ]);

        $this->post(route('prices.store'), $this->payload([
            'supplier_id' => $supplier->id,
            'valid_from' => '2026-09-15',
        ]))->assertSessionHasErrors('part_id');

        $this->assertDatabaseMissing('part_prices', [
            'supplier_id' => $supplier->id,
            'part_id' => $this->part()->id,
        ]);
    }

    public function test_same_supplier_and_part_with_other_date_is_allowed(): void
    {
        $this->post(route('prices.store'), $this->payload())->assertRedirect();

        $this->post(route('prices.store'), $this->payload(['valid_from' => '2026-10-01', 'price' => 13000]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, PartPrice::where('part_id', $this->part()->id)->count());
    }

    public function test_update_and_destroy(): void
    {
        $price = PartPrice::create($this->payload());

        $this->put(route('prices.update', $price), $this->payload(['price' => 9999]))
            ->assertRedirect(route('prices.index'));

        $this->assertSame(9999.0, (float) $price->fresh()->price);

        $this->delete(route('prices.destroy', $price))->assertRedirect(route('prices.index'));
        $this->assertDatabaseMissing('part_prices', ['id' => $price->id]);
    }
}
