<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\Machine;
use Illuminate\Database\Seeder;

/**
 * Lokasi non-rak untuk pengiriman barang jadi:
 * - satu lokasi per mesin (asal barang jadi sementara), dan
 * - satu area pengiriman (tujuan pemindahan sebelum barang dimuat).
 *
 * Idempoten: kode adalah business key. Tipe ditetapkan ulang karena tipe
 * menentukan perilaku sistem, sedangkan status aktif milik admin.
 */
class DeliveryLocationSeeder extends Seeder
{
    public const DELIVERY_PREP_CODE = 'DELIVERY-PREP';

    public function run(): void
    {
        $machines = Machine::query()->get(['machine_code', 'machine_name']);

        foreach ($machines as $machine) {
            $code = $this->normalizeCode((string) $machine->machine_code);
            if ($code === '') {
                continue;
            }

            $location = Location::withTrashed()->firstOrNew(['code' => $code]);
            $location->name = (string) ($machine->machine_name ?: $machine->machine_code);
            $location->type = Location::TYPE_MACHINE;

            if (! $location->exists) {
                $location->is_active = true;
            }

            $location->save();
        }

        $prep = Location::withTrashed()->firstOrNew(['code' => self::DELIVERY_PREP_CODE]);
        $prep->name = 'Delivery Preparation Area';
        $prep->type = Location::TYPE_DELIVERY_PREP;

        if (! $prep->exists) {
            $prep->is_active = true;
        }

        $prep->save();
    }

    /**
     * Kode lokasi mesin mengikuti kode mesin, dirapikan ke bentuk aman.
     */
    private function normalizeCode(string $code): string
    {
        $trimmed = strtoupper(trim($code));

        return preg_replace('/[^A-Z0-9_-]+/', '-', $trimmed) ?? '';
    }
}
