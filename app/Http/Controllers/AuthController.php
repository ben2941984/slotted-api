<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => 'required|string|max:120',
            'email'    => 'required|email|unique:users',
            'password' => 'required|string|min:8',
        ]);

        $user  = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        $token = $user->createToken('app')->plainTextToken;

        return response()->json(['token' => $token, 'name' => $user->name], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Invalid credentials.']);
        }

        $token = $user->createToken('app')->plainTextToken;

        return response()->json(['token' => $token, 'name' => $user->name]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['name' => $request->user()->name, 'email' => $request->user()->email]);
    }
}
