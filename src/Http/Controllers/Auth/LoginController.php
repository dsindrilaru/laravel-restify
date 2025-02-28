<?php

namespace Binaryk\LaravelRestify\Http\Controllers\Auth;

use Binaryk\LaravelRestify\Tests\Fixtures\User\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'password' => ['required'],
        ]);

        /** * @var User $user */

        if (! $user = config('restify.auth.user_model')::query()
            ->whereEmail($request->input('email'))
            ->first()) {
            abort(401, 'Invalid credentials.');
        }

        if (! Hash::check($request->input('password'), $user->password)) {
            abort(401, 'Invalid credentials.');
        }

        return data([
            'user' => $user,
            'token' => $user->createToken('login'),
        ]);
    }
}
