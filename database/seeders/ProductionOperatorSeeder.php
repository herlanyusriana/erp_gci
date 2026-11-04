<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ProductionOperatorSeeder extends Seeder
{
    public const DEFAULT_PASSWORD = 'gci2026';

    /**
     * Daftar 92 karyawan Produksi (5 Foreman + 87 Operator) khusus APK.
     *
     * @var list<string>
     */
    public const EMPLOYEES = [
        // FOREMAN PROD (5)
        'SUHARJA',
        'TEGUH HERU SUSANTO',
        'TURKHAM',
        'SUHENDRI',
        'BUDI PURWANTO',
        // OPERATOR (87)
        'MIFTAHILMI',
        'RAHMAD HIDAYAT',
        'SUDARMONO',
        'M TONI HARDI',
        'UTIN',
        'SUWANDI',
        'SUPRIYANTO B',
        'SUMANTO',
        'SULHI',
        'SUHERI',
        'SAMSUDIN',
        'RUSMADI',
        'ROIS HADI SETIAWAN',
        'RIYAN ARDIANSYAH',
        'NURHOJIN',
        'NUR ROHMAN',
        'MUHTADI',
        'MUHAMAD FIKRI AWWALUDIN',
        'MAD USUP',
        'JAMRULLOH',
        'ISDIYANTO',
        'HERRY MARYANTORO',
        'HERLI YUNANDA',
        'HARIS',
        'HADIDI MUSTAKIM',
        'FERY FERDIANSYAH',
        'FANNI EKO PRASETYO',
        'EMON SAEMON',
        'EDI YULIANTO',
        'EDI JUMRI',
        'DWI SAPUTRA',
        'DWI AGE PRASETYO',
        'DEDI SUPRIYADI',
        'DAVID SUHARYONO',
        'CATUR KURNIAWAN',
        'ASEP NASRULLOH',
        'ARIF HIDAYAT',
        'ACHMAD SUBARJO',
        'SUCI FEBRIYANTI',
        'DENDI SETIANDI',
        'ALFA RIZKI MUHAMAD TAUHID',
        'SEPTI IKHWANIAH',
        'FIKRI OKTAVIANSYAH',
        'ALGI MAGROBBI',
        'DENI',
        'MAMAN FIRMANSYAH',
        'MUHAMAD ROCHMAN',
        'IIS NURJANAH',
        'SAFIRA AULIA PUTRI',
        'GISHELA ALISYA PUTRI',
        'AGI MALANDA',
        'MUHAMAD IYAN SURYANA',
        'ASEP PERMANA',
        'DIKI YULIANTO',
        'RISKI HOWERUL ANWAR',
        'SHANTI HERAWATI',
        'MUHAMAD ANDI FAJAR',
        'MUHAMAD IYAN SUNARDI',
        'DWIKI ADI PURBOWO',
        'DIKI SUDARMANTO',
        'YADI',
        'YULI ASEP SETIAWAN',
        'M. FARDON',
        'MOCHAMMAD ROCHMATTULLOH',
        'RASIDI',
        'ELISA DAMAYANTI',
        'AWALUDIN',
        'MUHAMAD RAFIUDDAROJAT',
        'AHMAD JAELANI',
        'ELSA AYU PUSPITASARI',
        'JHOAN NAIYA ALIFAH',
        'CLARA TALITHA',
        'EZRA ARTHUR SHEILO PUTRA',
        'MUNIB MUKHSIN',
        'AHLALUS SAPTONI',
        'AGUNG TRI ARYANTO',
        'YOANA AULIA SURATMAN',
        'HENDI ADITYA',
        'SITI NURUL FAUZIYAH',
        'ELSI ANITA PUTRI',
        'HANA KAILA MEICHA',
        'YUSI OKTAVIANTI',
        'FARID AFRIYAN',
        'NURFITRAH ALI ZAYUNI',
        'APEPEP DODI',
        'SADUMI',
        'NANA SURYATNA',
    ];

    public static function emailFor(string $name): string
    {
        $clean = strtolower(preg_replace('/[^a-zA-Z0-9 ]+/', '', $name) ?? '');
        $parts = array_values(array_filter(explode(' ', $clean)));

        return implode('.', $parts).'@geumcheon.local';
    }

    public function run(): void
    {
        $productionRole = Role::firstOrCreate(
            ['name' => 'production'],
            ['label' => 'Production'],
        );

        $hashedPassword = Hash::make(self::DEFAULT_PASSWORD);
        $now = now();

        foreach (self::EMPLOYEES as $name) {
            $email = self::emailFor($name);

            $user = User::withTrashed()->updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => $hashedPassword,
                    'is_active' => true,
                    'apk_only' => true,
                    'email_verified_at' => $now,
                    'deleted_at' => null,
                ],
            );

            $user->roles()->syncWithoutDetaching([$productionRole->id]);
        }
    }
}
