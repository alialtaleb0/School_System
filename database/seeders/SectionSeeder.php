<?php

namespace Database\Seeders;

use App\Models\Level;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SectionSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            'Grade 1' => ['A', 'B'],
            'Grade 2' => ['A', 'B', 'C', 'D'],
            'Grade 3' => ['A', 'B', 'C'],
            'Grade 4' => ['A', 'B', 'C', 'D', 'E'],
            'Grade 5' => ['A', 'B'],
            'Grade 6' => ['A', 'B', 'C'],
            'Grade 7' => ['A', 'B', 'C', 'D'],
            'Grade 8' => ['A', 'B', 'C'],
            'Grade 9' => ['A', 'B', 'C', 'D'],
            'Grade 10' => ['Sci-A (Scientific)', 'Lit-A (Literary)'],
            'Grade 11' => ['Sci-A (Scientific)', 'Sci-B (Scientific)', 'Sci-C (Scientific)', 'Lit-A (Literary)'],
            'Grade 12 (Baccalaureate)' => ['Sci-A (Scientific)', 'Sci-B (Scientific)', 'Sci-C (Scientific)', 'Lit-A (Literary)', 'Lit-B (Literary)'],
        ];

        foreach ($data as $levelName => $sections) {
            try {
                // نطبغ في التيرمنال لنرى أين يقف
                $this->command->info("Processing: {$levelName}");

                $level = Level::firstOrCreate(['name' => $levelName]);

                foreach ($sections as $name) {
                    DB::table('sections')->updateOrInsert(
                        ['level_id' => $level->id, 'name' => $name],
                        ['created_at' => now(), 'updated_at' => now()]
                    );
                }
            } catch (\Exception $e) {
                // إذا حدث أي خطأ سيطبعه لك باللون الأحمر فوراً في الشاشة
                $this->command->error("Error in {$levelName}: " . $e->getMessage());
            }
        }
    }
}
