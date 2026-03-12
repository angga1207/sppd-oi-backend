<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class KlasifikasiSeeder extends Seeder
{
    /**
     * Import klasifikasi data from external SQL file.
     */
    public function run(): void
    {
        $sqlPath = base_path('../sql_klasifikasi/KLASIFIKASI SURAT (DATABASE).sql');

        if (!file_exists($sqlPath)) {
            $this->command->warn('SQL file not found: ' . $sqlPath);
            return;
        }

        $sql = file_get_contents($sqlPath);

        // Remove DDL statements — keep only INSERT statements
        // Remove everything before the first INSERT
        $lines = explode("\n", $sql);
        $insertLines = [];
        $capturing = false;

        foreach ($lines as $line) {
            // Start capturing when we see INSERT INTO
            if (str_starts_with($line, 'INSERT INTO')) {
                $capturing = true;
            }

            if ($capturing) {
                $insertLines[] = $line;

                // Stop capturing when line ends with ); (end of multi-row INSERT)
                if (str_ends_with(trim($line), ');')) {
                    $capturing = false;
                }
            }
        }

        if (empty($insertLines)) {
            $this->command->warn('No INSERT statements found in SQL file.');
            return;
        }

        $insertSql = implode("\n", $insertLines);

        DB::statement('TRUNCATE TABLE klasifikasi RESTART IDENTITY CASCADE');
        DB::unprepared($insertSql);

        $count = DB::table('klasifikasi')->count();
        $this->command->info("Imported {$count} klasifikasi records.");
    }
}
