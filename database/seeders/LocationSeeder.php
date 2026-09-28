<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

/**
 * Rak gudang sesuai Warehouse Map (Layout).
 *
 * Idempoten: kode adalah business key, jadi seeder aman dijalankan berulang.
 * Lokasi yang sudah ada hanya namanya yang disegarkan — status aktif dan
 * penghapusan oleh admin tidak ditimpa.
 */
class LocationSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->locations() as $code => $name) {
            $location = Location::withTrashed()->firstOrNew(['code' => $code]);
            $location->name = $name;

            if (! $location->exists) {
                $location->is_active = true;
            }

            $location->save();
        }
    }

    /**
     * @return array<string, string> kode => nama
     */
    private function locations(): array
    {
        return [
            'Rack-1' => 'Rak 1',
            'Rack-2' => 'Rak 2',
            'Rack-3' => 'Rak 3',
            'Rack-4' => 'Rak 4',
            'Rack-5' => 'Rak 5',
            'S1' => 'S1',
            'S2' => 'S2',
        ];
    }
}
