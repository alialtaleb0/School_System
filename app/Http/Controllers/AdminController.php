<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use Illuminate\Http\Request;

class AdminController extends Controller
{
   public function store(Request $request)
{
    $data = $request->validate([
        'name' => 'required',
        'email' => 'required|unique:users',
        'password' => 'required',
        'level_id' => 'required',
        'section_id' => 'required',
        'academic_year' => 'required',
    ]);

    $user = User::create([
        'name' => $data['name'],
        'email' => $data['email'],
        'password' => bcrypt($data['password']),
        'role' => 'student',
    ]);

    $student = Student::create([
        'user_id' => $user->id,
    ]);

    StudentEnrollment::create([
        'student_id' => $student->id,
        'level_id' => $data['level_id'],
        'section_id' => $data['section_id'],
        'academic_year' => $data['academic_year'],
        'student_number' => 'STD-' . rand(1000,9999),
    ]);

    return response()->json(['message' => __('Student created')]);
}









public function completeProfile(Request $request)
{
    $user = auth()->user();

    $data = $request->validate([
        'level_id' => 'required|exists:levels,id',
        'section_id' => 'required|exists:sections,id',
        'academic_year' => 'required',
    ]);

    $student = $user->student;

    StudentEnrollment::create([
        'student_id' => $student->id,
        'level_id' => $data['level_id'],
        'section_id' => $data['section_id'],
        'academic_year' => $data['academic_year'],
        'student_number' => 'STD-' . rand(1000,9999),
    ]);

    return response()->json(['message' => __('Profile completed')]);
}








public function index()
{
    return Student::with([
        'user',
        'enrollments.level',
        'enrollments.section'
    ])->get();
}





public function show($id)
{
    return Student::with('user', 'enrollments')->findOrFail($id);
}






public function destroy($id)
{
    Student::findOrFail($id)->delete();
    return response()->json(['message' => __('Deleted')]);
}
}
