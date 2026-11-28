<?php

namespace App\Http\Controllers\Store\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerDataRequest;
use App\Services\Store\CustomerAccounts;
use App\Services\Store\CustomerRegistration;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class RegisterController extends Controller
{
    public function create(): View
    {
        return view('store.account.register');
    }

    public function store(CustomerDataRequest $request, CustomerRegistration $registration): RedirectResponse
    {
        $registration->start($request->session(), $request->validated());

        return to_route('store.register.verification');
    }

    public function verification(Request $request, CustomerRegistration $registration): View|RedirectResponse
    {
        $pending = $registration->pending($request->session());

        return $pending === null ? to_route('store.register.create') : view('store.account.verify-registration', [
            'email' => $pending['data']['email'],
        ]);
    }

    public function verify(Request $request, CustomerRegistration $registration, CustomerAccounts $accounts): RedirectResponse
    {
        $pending = $registration->pending($request->session());
        if ($pending === null) {
            return $this->expired();
        }

        $code = $request->input('code');
        if (! is_string($code) || ! preg_match('/\A[0-9]{6}\z/', $code)) {
            return to_route('store.register.verification')->withErrors(['code' => 'Escribe los seis dígitos del correo.']);
        }

        try {
            $customer = $registration->complete($request->session(), $pending, $code);
        } catch (ValidationException $exception) {
            $route = $registration->pending($request->session()) === null ? 'store.register.create' : 'store.register.verification';

            return to_route($route)->withErrors($exception->errors());
        }

        Auth::guard('customer')->login($customer);
        $accounts->startSession($request->session());

        return redirect()->intended(route('store.home'))->with('status', 'Tu cuenta está lista.');
    }

    public function resend(Request $request, CustomerRegistration $registration): RedirectResponse
    {
        $pending = $registration->pending($request->session());
        if ($pending === null) {
            return $this->expired();
        }

        try {
            $registration->resend($request->session(), $pending);
        } catch (ValidationException $exception) {
            return to_route('store.register.verification')->withErrors($exception->errors());
        }

        return to_route('store.register.verification')->with('status', 'Enviamos un código nuevo a tu correo.');
    }

    private function expired(): RedirectResponse
    {
        return to_route('store.register.create')->withErrors(['email' => 'El registro venció. Completa los datos para empezar de nuevo.']);
    }
}
