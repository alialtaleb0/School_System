<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SubjectSeeder extends Seeder
{
    public function run(): void
    {
        $subjects = [
            ['name' => 'Mathematics'],
            ['name' => 'Arabic Language'],
            ['name' => 'English Language'],
            ['name' => 'Computer Science'],
            ['name' => 'Physics'],
            ['name' => 'Chemistry'],
            ['name' => 'Biology'],
            ['name' => 'History'],
            ['name' => 'Geography'],
            ['name' => 'Philosophy'],
        ];

        DB::table('subjects')->insert($subjects);
    }
}
