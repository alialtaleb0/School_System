<?php

namespace Database\Seeders;

use App\Models\Exam;
use App\Models\Grade;
use App\Models\Level;
use App\Models\ParentModel;
use App\Models\Program;
use App\Models\Schedule;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\Timetable;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ============================================================
        // 0️⃣ تفريغ الجداول بالترتيب الصحيح
        // ============================================================
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('wallet_transactions')->truncate();
        DB::table('wallets')->truncate();
        Grade::truncate();
        Exam::truncate();
        Schedule::truncate();
        Timetable::truncate();
        StudentEnrollment::truncate();
        DB::table('parents')->truncate();
        Student::truncate();
        Teacher::truncate();
        DB::table('subject_teacher')->truncate();
        DB::table('level_subject')->truncate();
        DB::table('program_subject')->truncate();
        Section::truncate();
        Subject::truncate();
        Program::truncate();
        Level::truncate();
        User::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // ============================================================
        // 1️⃣ المستخدم الأدمن
        // ============================================================
        User::create([
            'first_name' => 'Admin',
            'last_name'  => 'System',
            'email'      => 'admin@school.com',
            'phone'      => '0911111111',
            'address'    => 'Damascus, Syria',
            'password'   => Hash::make('password123'),
            'role'       => 'admin',
        ]);

        // ============================================================
        // 2️⃣ المستويات + البرامج + المواد + الشعب
        //    (عبر الـ Seeders الموجودة)
        // ============================================================
        $this->call(LevelSeeder::class);
        $this->call(ProgramSeeder::class);
        $this->call(SubjectSeeder::class);
        $this->call(SectionSeeder::class);
        $this->call(ProgramSubjectSeeder::class);

        // ============================================================
        // 3️⃣ جلب البيانات بعد إنشائها
        // ============================================================
        $subjects   = Subject::all()->keyBy('name');
        $scientific = Program::where('name', 'Scientific')->first();
        $literary   = Program::where('name', 'Literary')->first();

        // جلب الشعب المطلوبة للـ Seeder
        $grade1  = Level::where('name', 'Grade 1')->first();
        $grade10 = Level::where('name', 'Grade 10')->first();

        $section1A  = Section::where('level_id', $grade1->id)->where('name', 'A')->first();
        $section1B  = Section::where('level_id', $grade1->id)->where('name', 'B')->first();
        $section10S = Section::where('level_id', $grade10->id)->where('name', 'Sci-A (Scientific)')->first();
        $section10L = Section::where('level_id', $grade10->id)->where('name', 'Lit-A (Literary)')->first();

        // ربط program_id بالشعب Grade 10+ (لأن SectionSeeder ما بيربطها)
        $section10S->update(['program_id' => $scientific->id]);
        $section10L->update(['program_id' => $literary->id]);

        // ربط Grade 11
        $grade11 = Level::where('name', 'Grade 11')->first();
        Section::where('level_id', $grade11->id)
            ->where('name', 'like', 'Sci%')
            ->update(['program_id' => $scientific->id]);
        Section::where('level_id', $grade11->id)
            ->where('name', 'like', 'Lit%')
            ->update(['program_id' => $literary->id]);

        // ربط Grade 12
        $grade12 = Level::where('name', 'Grade 12 (Baccalaureate)')->first();
        Section::where('level_id', $grade12->id)
            ->where('name', 'like', 'Sci%')
            ->update(['program_id' => $scientific->id]);
        Section::where('level_id', $grade12->id)
            ->where('name', 'like', 'Lit%')
            ->update(['program_id' => $literary->id]);

        // ============================================================
        // 4️⃣ المعلّمون — الأدمن يضيفهم مباشرة → status=approved
        // ============================================================
        $teacherData = [
            ['first_name' => 'Karim',  'last_name' => 'Hassan',  'email' => 'karim@school.com',  'subjects' => ['Mathematics', 'Physics']],
            ['first_name' => 'Layla',  'last_name' => 'Nasser',  'email' => 'layla@school.com',  'subjects' => ['Arabic Language', 'History']],
            ['first_name' => 'Omar',   'last_name' => 'Khalil',  'email' => 'omar@school.com',   'subjects' => ['Chemistry', 'Biology']],
            ['first_name' => 'Rania',  'last_name' => 'Saleh',   'email' => 'rania@school.com',  'subjects' => ['English Language', 'Geography']],
            ['first_name' => 'Tarek',  'last_name' => 'Mansour', 'email' => 'tarek@school.com',  'subjects' => ['Computer Science']],
        ];

        $teachers = [];
        foreach ($teacherData as $td) {
            $user = User::create([
                'first_name' => $td['first_name'],
                'last_name'  => $td['last_name'],
                'email'      => $td['email'],
                'phone'      => '09' . rand(10000000, 99999999),
                'address'    => 'Damascus, Syria',
                'password'   => Hash::make('password123'),
                'role'       => 'teacher',
            ]);

            $teacher = Teacher::create([
                'user_id'        => $user->id,
                'status'         => 'approved',
                'pending_action' => 'none',
            ]);

            $subjectIds = collect($td['subjects'])
                ->map(fn($s) => $subjects[$s]->id)
                ->toArray();
            $teacher->subjects()->sync($subjectIds);

            $teachers[$td['first_name']] = $teacher;
        }

        // ============================================================
        // 5️⃣ الطلاب — الأدمن يضيفهم مباشرة + موافقة فورية
        // ============================================================
        $studentData = [
            ['first_name' => 'Ali',    'last_name' => 'Ahmad',   'email' => 'ali@student.com',    'section' => $section1A,  'year' => '2025-2026', 'num' => 'STU001'],
            ['first_name' => 'Sara',   'last_name' => 'Khalil',  'email' => 'sara@student.com',   'section' => $section1A,  'year' => '2025-2026', 'num' => 'STU002'],
            ['first_name' => 'Yusuf',  'last_name' => 'Omar',    'email' => 'yusuf@student.com',  'section' => $section1B,  'year' => '2025-2026', 'num' => 'STU003'],
            ['first_name' => 'Nour',   'last_name' => 'Saad',    'email' => 'nour@student.com',   'section' => $section10S, 'year' => '2025-2026', 'num' => 'STU004'],
            ['first_name' => 'Hassan', 'last_name' => 'Mahmoud', 'email' => 'hassan@student.com', 'section' => $section10L, 'year' => '2025-2026', 'num' => 'STU005'],
        ];

        $students = [];
        foreach ($studentData as $sd) {
            $user = User::create([
                'first_name' => $sd['first_name'],
                'last_name'  => $sd['last_name'],
                'email'      => $sd['email'],
                'phone'      => '09' . rand(10000000, 99999999),
                'address'    => 'Damascus, Syria',
                'password'   => Hash::make('password123'),
                'role'       => 'student',
            ]);

            $student = Student::create([
                'user_id'        => $user->id,
                'student_number' => $sd['num'],
                'date_of_birth'  => '2010-01-01',
            ]);

            StudentEnrollment::create([
                'student_id'     => $student->id,
                'level_id'       => $sd['section']->level_id,
                'section_id'     => $sd['section']->id,
                'academic_year'  => $sd['year'],
                'student_number' => $sd['num'],
                'status'         => 'approved',
            ]);

            $students[$sd['first_name']] = $student;
        }

        // ============================================================
        // 6️⃣ الآباء — موافقة فورية من الأدمن
        // ============================================================
        $parentData = [
            ['first_name' => 'Ahmad',  'last_name' => 'Ali',  'email' => 'ahmad.parent@school.com',  'children' => ['Ali', 'Sara']],
            ['first_name' => 'Fatima', 'last_name' => 'Omar', 'email' => 'fatima.parent@school.com', 'children' => ['Yusuf']],
        ];

        foreach ($parentData as $pd) {
            $user = User::create([
                'first_name' => $pd['first_name'],
                'last_name'  => $pd['last_name'],
                'email'      => $pd['email'],
                'phone'      => '09' . rand(10000000, 99999999),
                'address'    => 'Damascus, Syria',
                'password'   => Hash::make('password123'),
                'role'       => 'parent',
            ]);

            $parent = ParentModel::create([
                'user_id'             => $user->id,
                'status'              => 'approved',
                'pending_student_ids' => null,
            ]);

            $childIds = collect($pd['children'])
                ->map(fn($name) => $students[$name]->id)
                ->toArray();
            Student::whereIn('id', $childIds)->update(['parent_id' => $parent->id]);
        }

        // ============================================================
        // 7️⃣ الجداول الدراسية (Timetables) + الحصص (Schedules)
        // ============================================================
        $timetable1A = Timetable::create([
            'section_id' => $section1A->id,
            'name'       => 'First Term 2025-2026',
            'start_date' => '2025-09-01',
            'end_date'   => '2026-01-30',
        ]);

        $timetable10S = Timetable::create([
            'section_id' => $section10S->id,
            'name'       => 'First Term 2025-2026',
            'start_date' => '2025-09-01',
            'end_date'   => '2026-01-30',
        ]);

        $schedules1A = [
            ['subject' => 'Mathematics',    'teacher' => 'Karim', 'day' => 'sunday',    'period' => 1],
            ['subject' => 'Arabic Language','teacher' => 'Layla', 'day' => 'sunday',    'period' => 2],
            ['subject' => 'English Language','teacher'=> 'Rania', 'day' => 'monday',    'period' => 1],
            ['subject' => 'History',        'teacher' => 'Layla', 'day' => 'tuesday',   'period' => 1],
            ['subject' => 'Mathematics',    'teacher' => 'Karim', 'day' => 'wednesday', 'period' => 1],
        ];

        foreach ($schedules1A as $s) {
            Schedule::create([
                'timetable_id' => $timetable1A->id,
                'subject_id'   => $subjects[$s['subject']]->id,
                'teacher_id'   => $teachers[$s['teacher']]->id,
                'day'          => $s['day'],
                'period'       => $s['period'],
            ]);
        }

        $schedules10S = [
            ['subject' => 'Mathematics',     'teacher' => 'Karim', 'day' => 'sunday',    'period' => 1],
            ['subject' => 'Physics',         'teacher' => 'Karim', 'day' => 'sunday',    'period' => 2],
            ['subject' => 'Chemistry',       'teacher' => 'Omar',  'day' => 'monday',    'period' => 1],
            ['subject' => 'Biology',         'teacher' => 'Omar',  'day' => 'tuesday',   'period' => 1],
            ['subject' => 'Computer Science','teacher' => 'Tarek', 'day' => 'wednesday', 'period' => 1],
            ['subject' => 'English Language','teacher' => 'Rania', 'day' => 'thursday',  'period' => 1],
        ];

        foreach ($schedules10S as $s) {
            Schedule::create([
                'timetable_id' => $timetable10S->id,
                'subject_id'   => $subjects[$s['subject']]->id,
                'teacher_id'   => $teachers[$s['teacher']]->id,
                'day'          => $s['day'],
                'period'       => $s['period'],
            ]);
        }

        // ============================================================
        // 8️⃣ الامتحانات (Exams)
        // ============================================================
        $exam1 = Exam::create([
            'subject_id' => $subjects['Mathematics']->id,
            'teacher_id' => $teachers['Karim']->id,
            'name'       => 'Mathematics Midterm',
            'start_time' => '2025-11-10 09:00:00',
            'end_time'   => '2025-11-10 11:00:00',
        ]);

        $exam2 = Exam::create([
            'subject_id' => $subjects['Arabic Language']->id,
            'teacher_id' => $teachers['Layla']->id,
            'name'       => 'Arabic Language Midterm',
            'start_time' => '2025-11-12 09:00:00',
            'end_time'   => '2025-11-12 11:00:00',
        ]);

        $exam3 = Exam::create([
            'subject_id' => $subjects['Physics']->id,
            'teacher_id' => $teachers['Karim']->id,
            'name'       => 'Physics Midterm',
            'start_time' => '2025-11-14 09:00:00',
            'end_time'   => '2025-11-14 11:00:00',
        ]);

        // ============================================================
        // 9️⃣ الدرجات (Grades)
        // ============================================================
        $gradesData = [
            ['student' => 'Ali',  'exam' => $exam1, 'teacher' => 'Karim', 'mark' => 85],
            ['student' => 'Sara', 'exam' => $exam1, 'teacher' => 'Karim', 'mark' => 90],
            ['student' => 'Ali',  'exam' => $exam2, 'teacher' => 'Layla', 'mark' => 78],
            ['student' => 'Sara', 'exam' => $exam2, 'teacher' => 'Layla', 'mark' => 88],
            ['student' => 'Nour', 'exam' => $exam3, 'teacher' => 'Karim', 'mark' => 92],
        ];

        foreach ($gradesData as $g) {
            Grade::create([
                'student_id' => $students[$g['student']]->id,
                'exam_id'    => $g['exam']->id,
                'teacher_id' => $teachers[$g['teacher']]->id,
                'mark'       => $g['mark'],
            ]);
        }

        // ============================================================
        // ✅ ملخص
        // ============================================================
        $this->command->info('');
        $this->command->info('✅ تم إدخال البيانات بنجاح بإذن الله تعالى');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('👤 Admin   : admin@school.com');
        $this->command->info('👨‍🏫 Teachers: karim / layla / omar / rania / tarek @school.com');
        $this->command->info('👦 Students: ali / sara / yusuf / nour / hassan @student.com');
        $this->command->info('👨‍👩‍👦 Parents : ahmad.parent / fatima.parent @school.com');
        $this->command->info('🔑 Password: password123 (للجميع)');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }
}
