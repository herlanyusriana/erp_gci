<?php

namespace Database\Seeders;

use App\Models\ConfigMaster;
use Illuminate\Database\Seeder;

class ConfigMasterSeeder extends Seeder
{
    public function run(): void
    {
        $config = [
            ['SYSTEM', 'company_name', 'PT Geum Cheon Indo', 'string'],
            ['SYSTEM', 'application_name', 'Geum Cheon ERP', 'string'],
            ['SYSTEM', 'timezone', 'Asia/Jakarta', 'string'],
            ['SYSTEM', 'date_format', 'DD/MM/YYYY', 'string'],
            ['SYSTEM', 'currency', 'IDR', 'string'],
            ['SYSTEM', 'decimal_precision', '3', 'integer'],
            ['SYSTEM', 'default_language', 'id', 'string'],
            ['PART', 'default_uom', 'PCS', 'string'],
        ];

        foreach ($config as [$group, $key, $value, $dataType]) {
            ConfigMaster::updateOrCreate(
                ['group' => $group, 'key' => $key],
                ['value' => (string) $value, 'data_type' => $dataType, 'is_active' => true],
            );
        }
    }
}