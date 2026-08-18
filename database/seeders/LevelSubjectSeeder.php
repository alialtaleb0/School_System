<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LevelSubjectSeeder extends Seeder
{
    public function run(): void
    {
        // جميع المواد معرف لاحقاً
        $allSubjects = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]; // IDs of subjects

        // الصفوف من 1-9: بدون نوع (type = null)
        for ($levelId = 1; $levelId <= 9; $levelId++) {
            foreach ($allSubjects as $subjectId) {
                DB::table('level_subject')->insert([
                    'level_id' => $levelId,
                    'subject_id' => $subjectId,
                    'type' => null, // بدون نوع للصفوف 1-9
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // الصف العاشر: مواد علمية وأدبية
        $grade10Subjects = [
            // مواد علمية
            [1, 'Scientific'],    // Mathematics
            [4, 'Scientific'],    // Computer Science
            [5, 'Scientific'],    // Physics
            [6, 'Scientific'],    // Chemistry
            [7, 'Scientific'],    // Biology
            // مواد أدبية
            [8, 'literary'],      // History
            [9, 'literary'],      // Geography
            [10, 'literary'],     // Philosophy
            // مواد مشتركة
            [2, 'both'],          // Arabic Language
            [3, 'both'],          // English Language
        ];

        foreach ($grade10Subjects as [$subjectId, $type]) {
            DB::table('level_subject')->insert([
                'level_id' => 10,
                'subject_id' => $subjectId,
                'type' => $type,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // الصف الحادي عشر: نفس توزيع الصف العاشر
        foreach ($grade10Subjects as [$subjectId, $type]) {
            DB::table('level_subject')->insert([
                'level_id' => 11,
                'subject_id' => $subjectId,
                'type' => $type,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // الصف الثاني عشر: نفس توزيع الصفوف السابقة
        foreach ($grade10Subjects as [$subjectId, $type]) {
            DB::table('level_subject')->insert([
                'level_id' => 12,
                'subject_id' => $subjectId,
                'type' => $type,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
