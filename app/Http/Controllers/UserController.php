<?php

namespace App\Http\Controllers;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{

public function register (Request $request){

$request->validate([
    'first_name'=>'required|string|max:255',
    'last_name'=>'required|string|max:255',
    'address'=>'required|string|max:255',
    'phone'=>'required|string|max:20',
    'email'=>'required|string|email|max:255|unique:users,email',
    'password'=>'required|string|min:8|confirmed',
    'role' => 'nullable|in:student,parent,teacher,admin' // اختياري
]);

$user=User::create([
    'first_name'=>$request->first_name,
    'last_name'=>$request->last_name,
    'address'=>$request->address,
    'phone'=>$request->phone,
    'email'=>$request->email,
    'password'=>Hash::make($request->password),
    'role' => $request->role ?? 'student' // قيمة افتراضية
]);

return response()->json([
    'message'=>__('User Registered successfully'),
    'data'=>$user,
],201);

}


public function login (Request $request){
$request->validate([
'email'=>'required|string|email',
'password'=>'required|string']);
if (!Auth::attempt($request->only('email', 'password'))) {
    return response()->json(['message' => __('Invalid email or password')], 401);
}

$user = Auth::user();
$token = $user->createToken('auth_token')->plainTextToken;

return response()->json([
    'message' => __('Login successfully'),
    'token' => $token,
    'user' => $user,
], 200);
}}
