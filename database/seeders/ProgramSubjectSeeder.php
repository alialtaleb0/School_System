<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Program;
use App\Models\Subject;

class ProgramSubjectSeeder extends Seeder
{
    public function run(): void
    {
        // 🎓 جلب البرامج
        $scientific = Program::where('name', 'Scientific')->first();
        $literary   = Program::where('name', 'Literary')->first();

        if (!$scientific || !$literary) {
            return;
        }

        // 📚 المواد العلمية
        $scientificSubjects = ['Mathematics', 'Physics', 'Chemistry', 'Biology', 'Computer Science'];
        // 📖 المواد الأدبية
        $literarySubjects = ['History', 'Geography', 'Philosophy'];
        // 🎯 المواد المشتركة
        $commonSubjects = ['Arabic Language', 'English Language'];

        // 🔬 ربط البرنامج العلمي
        $scientificAndCommon = array_merge($scientificSubjects, $commonSubjects);
        foreach ($scientificAndCommon as $subjectName) {
            $subject = Subject::where('name', $subjectName)->first();
            if ($subject) {
                DB::table('program_subject')->updateOrInsert([
                    'program_id' => $scientific->id,
                    'subject_id' => $subject->id,
                ], [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // 📖 ربط البرنامج الأدبي
        $literaryAndCommon = array_merge($literarySubjects, $commonSubjects);
        foreach ($literaryAndCommon as $subjectName) {
            $subject = Subject::where('name', $subjectName)->first();
            if ($subject) {
                DB::table('program_subject')->updateOrInsert([
                    'program_id' => $literary->id,
                    'subject_id' => $subject->id,
                ], [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
