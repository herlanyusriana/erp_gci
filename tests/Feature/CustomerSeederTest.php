<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Database\Seeders\CustomerSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CustomerSeederTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin);
    }

    public function test_seeder_creates_all_ten_customers(): void
    {
        Customer::withTrashed()->where('customer_code', 'like', 'CRGCI%')->forceDelete();

        $this->seed(CustomerSeeder::class);

        $this->assertSame(10, Customer::where('customer_code', 'like', 'CRGCI%')->count());

        $lg = Customer::where('customer_code', 'CRGCI010001')->firstOrFail();
        $this->assertSame('PT. LG ELECTRONICS INDONESIA', $lg->customer_name);
        $this->assertSame('LGEIN', $lg->short_name);
        $this->assertSame('0010693232092000', $lg->npwp);
        $this->assertSame(Customer::BC_KB, $lg->bc_facility);
        $this->assertTrue($lg->is_active);

        $sanken = Customer::where('customer_code', 'CRGCI010002')->firstOrFail();
        $this->assertSame('SANKEN', $sanken->short_name);
        $this->assertSame('ELIS', $sanken->contact_person);
        $this->assertSame('sak.inventory01@sankencorp.com', $sanken->email);
        $this->assertSame(Customer::BC_NON_KB, $sanken->bc_facility);
    }

    public function test_seeder_is_idempotent(): void
    {
        Customer::withTrashed()->where('customer_code', 'like', 'CRGCI%')->forceDelete();

        $this->seed(CustomerSeeder::class);
        $this->seed(CustomerSeeder::class);

        $this->assertSame(10, Customer::where('customer_code', 'like', 'CRGCI%')->count());
    }

    public function test_seeder_updates_changed_values_without_duplicating(): void
    {
        Customer::withTrashed()->where('customer_code', 'like', 'CRGCI%')->forceDelete();

        Customer::create([
            'customer_code' => 'CRGCI010001',
            'customer_name' => 'Nama Lama',
            'is_active' => true,
        ]);

        $this->seed(CustomerSeeder::class);

        $this->assertSame(10, Customer::where('customer_code', 'like', 'CRGCI%')->count());
        $this->assertSame(
            'PT. LG ELECTRONICS INDONESIA',
            Customer::where('customer_code', 'CRGCI010001')->value('customer_name'),
        );
    }

    public function test_customer_master_accepts_the_new_company_fields(): void
    {
        $code = 'CR-TEST-'.uniqid();

        $this->post(route('customers.store'), [
            'customer_code' => $code,
            'customer_name' => 'PT. UJI COBA',
            'short_name' => 'UJI',
            'address' => 'Jl. Uji 1',
            'phone' => '+62-21-000',
            'contact_person' => 'BUDI',
            'email' => 'uji@example.com',
            'npwp' => '0012345678901000',
            'bc_facility' => Customer::BC_KB,
            'is_active' => true,
        ])->assertRedirect(route('customers.index'));

        $customer = Customer::where('customer_code', $code)->firstOrFail();
        $this->assertSame('UJI', $customer->short_name);
        $this->assertSame('BUDI', $customer->contact_person);
        $this->assertSame('0012345678901000', $customer->npwp);
        $this->assertSame(Customer::BC_KB, $customer->bc_facility);
    }

    public function test_invalid_bc_facility_is_rejected(): void
    {
        $this->post(route('customers.store'), [
            'customer_code' => 'CR-BAD-'.uniqid(),
            'customer_name' => 'PT. Salah',
            'bc_facility' => 'KB-PALSU',
        ])->assertSessionHasErrors('bc_facility');
    }

    public function test_search_matches_short_name_and_npwp(): void
    {
        Customer::withTrashed()->where('customer_code', 'like', 'CRGCI%')->forceDelete();
        $this->seed(CustomerSeeder::class);

        $byShort = $this->get(route('customers.index', ['search' => 'SANKEN']))->assertOk()->viewData('page');
        $this->assertSame('CRGCI010002', $byShort['props']['customers']['data'][0]['customer_code']);

        $byNpwp = $this->get(route('customers.index', ['search' => '0027639335451000']))->assertOk()->viewData('page');
        $this->assertSame('CRGCI010010', $byNpwp['props']['customers']['data'][0]['customer_code']);
    }
}
