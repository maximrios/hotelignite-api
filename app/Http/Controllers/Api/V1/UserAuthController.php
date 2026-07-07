<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserAuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'email|required',
            'password' => 'required',
        ]);

        if (! Auth::attempt($data)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $user = Auth::user();

        // Abilities por tipo (defensa en profundidad; la autorización real la
        // hacen las Policies). Los clients son de solo lectura.
        $abilities = $user->isClient() ? ['read'] : ['*'];

        $token = $user->createToken('api_token', $abilities)->plainTextToken;

        return response(['token' => $token, 'token_type' => 'Bearer']);
    }

    /**
     * Actualiza el perfil del usuario autenticado.
     *
     * El email es la credencial de acceso y no se puede modificar desde acá.
     */
    public function updateProfile(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $user->name = $data['name'];
        $user->save();

        return response()->json($user);
    }

    /**
     * Actualiza la contraseña del usuario autenticado.
     */
    public function updatePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['La contraseña actual es incorrecta.'],
            ]);
        }

        $user->password = Hash::make($data['password']);
        $user->save();

        // Revoca todos los tokens (incluido el actual) al cambiar la contraseña.
        $user->tokens()->delete();

        return response()->json(['message' => 'Contraseña actualizada. Volvé a iniciar sesión.']);
    }
}
