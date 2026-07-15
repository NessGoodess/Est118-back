<?php

namespace Database\Seeders;

use App\Enums\NfcReaderAudience;
use App\Enums\NfcReaderDirection;
use App\Models\NfcReaderSlot;
use Illuminate\Database\Seeder;

class NfcReaderSlotSeeder extends Seeder
{
    public function run(): void
    {
        $slots = [
            [
                'code' => 'boys-entry',
                'label' => 'Niños - Entrada',
                'audience' => NfcReaderAudience::BOYS,
                'direction' => NfcReaderDirection::ENTRY,
                'sort_order' => 1,
                'is_active' => true,
                'is_armed' => true,
            ],
            [
                'code' => 'girls-entry',
                'label' => 'Niñas - Entrada',
                'audience' => NfcReaderAudience::GIRLS,
                'direction' => NfcReaderDirection::ENTRY,
                'sort_order' => 2,
                'is_active' => true,
                'is_armed' => true,
            ],
            [
                'code' => 'boys-exit',
                'label' => 'Niños - Salida',
                'audience' => NfcReaderAudience::BOYS,
                'direction' => NfcReaderDirection::EXIT,
                'sort_order' => 3,
                'is_active' => false,
                'is_armed' => false,
            ],
            [
                'code' => 'girls-exit',
                'label' => 'Niñas - Salida',
                'audience' => NfcReaderAudience::GIRLS,
                'direction' => NfcReaderDirection::EXIT,
                'sort_order' => 4,
                'is_active' => false,
                'is_armed' => false,
            ],
        ];

        foreach ($slots as $slot) {
            NfcReaderSlot::updateOrCreate(
                ['code' => $slot['code']],
                $slot
            );
        }
    }
}
