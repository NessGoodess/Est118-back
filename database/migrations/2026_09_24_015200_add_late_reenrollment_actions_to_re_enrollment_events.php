<?php

use App\Enums\ReEnrollmentEventAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('re_enrollment_events')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        $values = implode("','", array_column(ReEnrollmentEventAction::cases(), 'value'));
        DB::statement("ALTER TABLE re_enrollment_events MODIFY COLUMN action ENUM('{$values}') NOT NULL");
    }

    public function down(): void
    {
        if (! Schema::hasTable('re_enrollment_events')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        $added = [
            ReEnrollmentEventAction::PRESENCE_CONFIRMED->value,
            ReEnrollmentEventAction::DROPOUT_CONFIRMED->value,
            ReEnrollmentEventAction::LATE_PLACED->value,
        ];

        DB::table('re_enrollment_events')
            ->whereIn('action', $added)
            ->delete();

        $values = implode("','", array_filter(
            array_column(ReEnrollmentEventAction::cases(), 'value'),
            fn (string $value) => ! in_array($value, $added, true)
        ));
        DB::statement("ALTER TABLE re_enrollment_events MODIFY COLUMN action ENUM('{$values}') NOT NULL");
    }
};
