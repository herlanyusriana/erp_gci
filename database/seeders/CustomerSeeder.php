<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;

/**
 * Master customer hasil migrasi data lama (kode CRGCI…).
 *
 * Idempoten: dicocokkan lewat `customer_code`, jadi menjalankan ulang hanya
 * memperbarui nama/alamat/kontak tanpa membuat duplikat.
 */
class CustomerSeeder extends Seeder
{
    /**
     * @var list<array<string, mixed>>
     */
    private const CUSTOMERS = [
        [
            'customer_code' => 'CRGCI010001',
            'customer_name' => 'PT. LG ELECTRONICS INDONESIA',
            'short_name' => 'LGEIN',
            'address' => 'JL. H. TABRI NO.1, DESA CIRARAB, KEC. LEGOK, KAB. TANGERANG',
            'phone' => '+62-21-5978320',
            'contact_person' => null,
            'email' => null,
            'npwp' => '0010693232092000',
            'bc_facility' => Customer::BC_KB,
        ],
        [
            'customer_code' => 'CRGCI010002',
            'customer_name' => 'PT. SANKEN ARGADWIJA',
            'short_name' => 'SANKEN',
            'address' => 'JL VETERAN KP CISEREH RT.000 RW.000, CUKANGGALIH, CURUG, KABUPATEN TANGERANG, BANTEN',
            'phone' => '+62-89685412527',
            'contact_person' => 'ELIS',
            'email' => 'sak.inventory01@sankencorp.com',
            'npwp' => '0010622892415000',
            'bc_facility' => Customer::BC_NON_KB,
        ],
        [
            'customer_code' => 'CRGCI010003',
            'customer_name' => 'PT. DAE SOUNG ELECTRIC COMPONENTS',
            'short_name' => 'DAE',
            'address' => 'JALAN TALAGA MAS IV NO.10 RT.004 RW.006, TALAGA, CIKUPA, KABUPATEN TANGERANG, BANTEN',
            'phone' => '+62-85778475694',
            'contact_person' => 'RANTI',
            'email' => 'ranti@daesoung.com',
            'npwp' => '0020063640055000',
            'bc_facility' => Customer::BC_KB,
        ],
        [
            'customer_code' => 'CRGCI010004',
            'customer_name' => 'PT. HAENG SUNG RAYA INDONESIA',
            'short_name' => 'HSRI',
            'address' => 'JL. ALBASIA UTARA I BLOK K0 1 NO.2 1D KELURAHAN SUKADAMI, KECAMATAN CIKARANG SELATAN, KABUPATEN',
            'phone' => '+62-89667025717',
            'contact_person' => 'ELIS',
            'email' => 'e.karisma@haengsung.co.id',
            'npwp' => '0018694810055000',
            'bc_facility' => Customer::BC_KB,
        ],
        [
            'customer_code' => 'CRGCI010005',
            'customer_name' => 'PT. HASURA MITRA GEMILANG',
            'short_name' => 'HASURA',
            'address' => 'INDUSTRI III KAV AB KOMP FACTO E1-E2 RT.000 RW.000, BUNDER, CIKUPA, KABUPATEN TANGERANG, BANTEN',
            'phone' => '+62-81213685735 / +62-81288425003',
            'contact_person' => 'AFNI / IRMA',
            'email' => 'wijayanurafni843@gmail.com',
            'npwp' => '0021993019451000',
            'bc_facility' => Customer::BC_NON_KB,
        ],
        [
            'customer_code' => 'CRGCI010006',
            'customer_name' => 'PT. HISYS ENGINEERING INDONESIA',
            'short_name' => 'HISYS',
            'address' => 'KOMPLEK INDUSTRI & PERGUDANGAN BLESSINDO JL H. TABRI, BLOK P9, NO. 228, BOJONGKAMAL, LEGOK, KAB. TANGERANG, BANTEN, 15825',
            'phone' => '+62-81210065094',
            'contact_person' => 'INDAH',
            'email' => 'indah@hisyseng.com',
            'npwp' => '0504010893019000',
            'bc_facility' => Customer::BC_NON_KB,
        ],
        [
            'customer_code' => 'CRGCI010007',
            'customer_name' => 'PT. JUAHN INDONESIA',
            'short_name' => 'JUAHN',
            'address' => 'JL PANGKALAN VI RT.001 RW.001, CIKETINGUDIK, BANTARGEBANG, KOTA BEKASI, JAWA BARAT',
            'phone' => '+62-81394499970 / +62-81295343764',
            'contact_person' => 'ARIF',
            'email' => 'rita@juahn.com',
            'npwp' => '0022708697431000',
            'bc_facility' => Customer::BC_KB,
        ],
        [
            'customer_code' => 'CRGCI010008',
            'customer_name' => 'PT. MOVING TECH',
            'short_name' => 'MOVING',
            'address' => 'KP. BUNGAOK RT.001 RW.001 DESA CARINGIN, CARINGIN, LEGOK, KAB. TANGERANG, BANTEN, 15826',
            'phone' => '+62-89601507845',
            'contact_person' => 'DEVI',
            'email' => 'devi_istari@ymail.com',
            'npwp' => '0018696534055000',
            'bc_facility' => Customer::BC_NON_KB,
        ],
        [
            'customer_code' => 'CRGCI010009',
            'customer_name' => 'PT. OS TEC INDONESIA',
            'short_name' => 'OS TEC',
            'address' => 'JL RAYA JAHA JATAKE, RW 004, RT 003, MALANG NENGAH, PAGEDANGAN, KAB. TANGERANG, BANTEN, 15857',
            'phone' => '+62-82120460422',
            'contact_person' => 'HARYATI',
            'email' => 'yanti_billa@yahoo.co.id',
            'npwp' => '0020265716052000',
            'bc_facility' => Customer::BC_NON_KB,
        ],
        [
            'customer_code' => 'CRGCI010010',
            'customer_name' => 'PT. SANGIL INDONESIA',
            'short_name' => 'SANGIL',
            'address' => 'KP. CIRARAB 002/003 CIRARAB, LEGOK, TANGERANG, BANTEN',
            'phone' => '+62-87720730021',
            'contact_person' => 'EDI',
            'email' => 'aji.wassy@gmail.com',
            'npwp' => '0027639335451000',
            'bc_facility' => Customer::BC_KB,
        ],
    ];

    public function run(): void
    {
        foreach (self::CUSTOMERS as $row) {
            Customer::updateOrCreate(
                ['customer_code' => $row['customer_code']],
                $row + ['is_active' => true],
            );
        }
    }
}
