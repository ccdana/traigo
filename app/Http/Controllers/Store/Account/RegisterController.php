<?php

namespace App\Http\Controllers\Store\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerDataRequest;
use App\Services\Store\CustomerAccounts;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class RegisterController extends Controller
{
    public function create(): View
    {
        return view('store.account.register');
    }

    public function store(CustomerDataRequest $request, CustomerAccounts $accounts): RedirectResponse
    {
        Auth::guard('customer')->login($accounts->register($request->validated()));
        $accounts->startSession($request->session());

        return redirect()->intended(route('store.home'))->with('status', 'Tu cuenta está lista.');
    }
}
