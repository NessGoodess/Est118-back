<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The card and attendance now identify a student by CURP.
     * Previous EST118- folios cannot be restored.
     */
    public function up(): void
    {
        $rows = DB::table('students')
            ->join('profiles', 'profiles.id', '=', 'students.profile_id')
            ->whereNotNull('profiles.national_id')
            ->select('students.id', 'profiles.national_id')
            ->get();

        foreach ($rows as $row) {
            $curp = strtoupper(trim((string) $row->national_id));
            if (! preg_match('/^[A-Z]{4}\d{6}[HMX][A-Z]{5}[A-Z0-9]\d$/', $curp)) {
                continue;
            }

            $taken = DB::table('students')
                ->where('credential_id', $curp)
                ->where('id', '!=', $row->id)
                ->exists();
            if ($taken) {
                continue;
            }

            DB::table('students')->where('id', $row->id)->update(['credential_id' => $curp]);
        }
    }

    public function down(): void
    {
        // Previous folios were replaced and are not recoverable.
    }
};
