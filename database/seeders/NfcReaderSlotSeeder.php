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
        // Two physical readers only. Entry vs exit is decided by horario, not by slot.
        $slots = [
            [
                'code' => 'boys-entry',
                'label' => 'Niños',
                'audience' => NfcReaderAudience::BOYS,
                'direction' => NfcReaderDirection::BOTH,
                'sort_order' => 1,
                'is_active' => true,
                'is_armed' => true,
            ],
            [
                'code' => 'girls-entry',
                'label' => 'Niñas',
                'audience' => NfcReaderAudience::GIRLS,
                'direction' => NfcReaderDirection::BOTH,
                'sort_order' => 2,
                'is_active' => true,
                'is_armed' => true,
            ],
            [
                'code' => 'boys-exit',
                'label' => 'Niños - Salida (obsoleto)',
                'audience' => NfcReaderAudience::BOYS,
                'direction' => NfcReaderDirection::EXIT,
                'sort_order' => 3,
                'is_active' => false,
                'is_armed' => false,
                'pcsc_name' => null,
            ],
            [
                'code' => 'girls-exit',
                'label' => 'Niñas - Salida (obsoleto)',
                'audience' => NfcReaderAudience::GIRLS,
                'direction' => NfcReaderDirection::EXIT,
                'sort_order' => 4,
                'is_active' => false,
                'is_armed' => false,
                'pcsc_name' => null,
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
