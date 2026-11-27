<?php

namespace App\Http\Controllers\Store\Account;

use App\Http\Controllers\Controller;
use App\Services\Store\CustomerAccounts;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Ingreso y salida de los clientes. */
class SessionController extends Controller
{
    public function create(): View
    {
        return view('store.account.login');
    }

    public function store(Request $request, CustomerAccounts $accounts): RedirectResponse
    {
        $data = $request->validate(
            ['email' => ['required', 'string', 'max:150'], 'password' => ['required', 'string', 'max:200']],
            [],
            ['email' => 'tu correo', 'password' => 'la contraseña'],
        );

        $credentials = [...CustomerAccounts::credentials($data['email']), 'password' => $data['password']];

        if (! Auth::guard('customer')->attempt($credentials, $request->boolean('remember'))) {
            // El mismo mensaje exista o no la cuenta.
            return back()->withInput($request->only('email'))->withErrors(['email' => 'Correo o contraseña incorrectos.']);
        }

        $accounts->startSession($request->session());

        return redirect()->intended(route('store.home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('customer')->logout();
        // En una computadora compartida no queda ni el carrito.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('store.home');
    }
}
