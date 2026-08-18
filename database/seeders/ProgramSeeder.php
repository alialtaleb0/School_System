<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProgramSeeder extends Seeder
{
    public function run(): void
    {
        $programs = [
            [
                'name' => 'Scientific',
                'description' => 'Science stream for mathematics and scientific subjects',
                'duration' => 3
            ],
            [
                'name' => 'Literary',
                'description' => 'Literature stream for humanities and languages',
                'duration' => 3
            ],
        ];

        DB::table('programs')->insert($programs);
    }
}