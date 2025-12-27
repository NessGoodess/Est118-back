<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetAutoIncrement extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:autoincrement {value=1000}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set auto-increment value for all tables';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $tablesWithAutoIncrement = [
            'addresses' => 1000,
            'classrooms' => 1000,
            'grade_levels' => 1000,
            'incident_types' => 1000,
        ];

        $value = $this->argument('value');

        $tables = DB::select('SHOW TABLES');

        foreach ($tables as $table) {
            $tableName = array_values((array) $table)[0];

            $result = DB::select("SHOW TABLE STATUS LIKE '{$tableName}'");
            $autoIncrement = $result[0]->Auto_increment ?? null;

            if (!empty($autoIncrement)) {
                DB::statement("ALTER TABLE `{$tableName}` AUTO_INCREMENT = {$value}");
                $this->info("+{$tableName} → AUTO_INCREMENT = {$value}");
            } else {
                $this->warn("-{$tableName} → No tiene Auto_increment o no se pudo leer");
            }
        }

        foreach ($tablesWithAutoIncrement as $tableName => $startValue) {
            DB::statement("ALTER TABLE `{$tableName}` AUTO_INCREMENT = {$startValue}");
            $this->info("✔ {$tableName} → AUTO_INCREMENT = {$startValue}");
        }

        $this->info("----");
        $this->info("All tables have been updated.");
    }
}
