<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            ProductionOperatorSeeder::class,
            ConfigMasterSeeder::class,
            UomSeeder::class,
            MasterDataSeeder::class,
            CustomerSeeder::class,
            LocationSeeder::class,
            DeliveryLocationSeeder::class,
            TrolleySeeder::class,
            BomSeeder::class,
            DerivedDataSeeder::class,
        ]);
    }
}
