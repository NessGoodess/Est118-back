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
                'label' => 'Panel 1',
                'audience' => NfcReaderAudience::BOYS,
                'direction' => NfcReaderDirection::BOTH,
                'sort_order' => 1,
                'is_active' => true,
                'is_armed' => true,
            ],
            [
                'code' => 'girls-entry',
                'label' => 'Panel 2',
                'audience' => NfcReaderAudience::GIRLS,
                'direction' => NfcReaderDirection::BOTH,
                'sort_order' => 2,
                'is_active' => true,
                'is_armed' => true,
            ],
            [
                'code' => 'boys-entry-2',
                'label' => 'Panel 3',
                'audience' => NfcReaderAudience::BOYS,
                'direction' => NfcReaderDirection::BOTH,
                'sort_order' => 3,
                'is_active' => false,
                'is_armed' => false,
                'pcsc_name' => null,
            ],
            [
                'code' => 'girls-entry-2',
                'label' => 'Panel 4',
                'audience' => NfcReaderAudience::GIRLS,
                'direction' => NfcReaderDirection::BOTH,
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
