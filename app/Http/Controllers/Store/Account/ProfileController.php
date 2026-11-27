<?php

namespace App\Http\Controllers\Store\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerDataRequest;
use App\Services\Store\CustomerAccounts;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/** «Mi cuenta»: los datos del cliente y su contraseña. */
class ProfileController extends Controller
{
    public function __construct(private readonly CustomerAccounts $accounts) {}

    public function edit(Request $request): View
    {
        return view('store.account.profile', ['account' => $request->user()]);
    }

    public function update(CustomerDataRequest $request): RedirectResponse
    {
        $this->accounts->updateData($request->user(), $request->validated());

        return back()->with('status', 'Tus datos se guardaron.');
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:customer'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ], [], ['current_password' => 'tu contraseña actual', 'password' => 'la contraseña nueva']);

        // Las otras sesiones se cierran solas: ya no coincide su contraseña.
        $this->accounts->changePassword($request->user(), $data['password']);

        return back()->with('status', 'Contraseña cambiada.');
    }
}
