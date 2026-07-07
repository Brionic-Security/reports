<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Auth;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;

final class AuthController
{
    public function showLogin(): Response
    {
        return Response::html(view('login', ['error' => Session::getFlash('error')]));
    }

    public function login(Request $request): Response
    {
        $email = (string) $request->input('email', '');
        $password = (string) $request->input('password', '');

        if (!Auth::attempt($email, $password)) {
            Session::flash('error', 'Invalid email or password.');
            return Response::redirect(app_url('login'));
        }

        $intended = Session::getFlash('intended');
        return Response::redirect(is_string($intended) && $intended !== '' ? app_url(ltrim($intended, '/')) : app_url('dashboard'));
    }

    public function logout(): Response
    {
        Auth::logout();
        return Response::redirect(app_url('login'));
    }
}
