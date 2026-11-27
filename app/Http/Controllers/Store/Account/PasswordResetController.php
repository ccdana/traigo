<?php

namespace App\Http\Controllers\Store\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Store\CustomerAccounts;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Throwable;

/** «Olvidé mi contraseña», solo para cuentas de cliente. */
class PasswordResetController extends Controller
{
    public function request(): View
    {
        return view('store.account.forgot');
    }

    public function email(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:150']], [], ['email' => 'tu correo']);

        try {
            Password::sendResetLink(CustomerAccounts::credentials($data['email']));
        } catch (Throwable $e) {
            // Un fallo del correo no debe revelar si la cuenta existe.
            report($e);
        }

        return back()->with('status', 'Si ese correo tiene una cuenta, te enviamos un enlace. Revisa también el correo no deseado.');
    }

    public function edit(Request $request, string $token): Response
    {
        return response()
            ->view('store.account.reset', ['token' => $token, 'email' => $request->query('email')])
            // La dirección lleva el enlace secreto.
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, CustomerAccounts $accounts): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:150'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(8)],
        ], [], ['email' => 'tu correo', 'password' => 'la contraseña']);

        $status = Password::reset(
            [
                ...CustomerAccounts::credentials($data['email']),
                'password' => $data['password'],
                'password_confirmation' => $request->input('password_confirmation'),
                'token' => $data['token'],
            ],
            function (User $customer, string $password) use ($accounts) {
                $accounts->changePassword($customer, $password);
                $accounts->closeSessions($customer);
            },
        );

        if ($status !== Password::PasswordReset) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => 'El enlace no es válido o ya venció. Pide uno nuevo.']);
        }

        return to_route('store.login')->with('status', 'Contraseña cambiada. Ya puedes ingresar.');
    }
}
