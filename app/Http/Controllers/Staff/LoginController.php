<?php

namespace App\Http\Controllers\Staff;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\PinLogin;
use App\Support\WebAdminAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * El panel identifica a Dirección por configuración y comprueba su clave.
 * La API móvil gestiona sus propios pasos de ingreso.
 */
class LoginController extends Controller
{
    public function __construct(
        private readonly PinLogin $pinLogin,
    ) {}

    public function create(): View|RedirectResponse
    {
        return WebAdminAccess::allows(Auth::guard('web')->user()) ? to_route('staff.dashboard') : view('staff.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(
            ['password' => ['required', 'string', 'max:12']],
            [],
            ['password' => 'la clave de Dirección'],
        );

        try {
            $user = $this->pinLogin->check(config('traigo.web.admin_code'), $data['password']);
        } catch (ApiException $e) {
            $message = $e->getStatusCode() === 429 ? $e->getMessage() : 'La clave de Dirección es incorrecta.';

            return back()->withErrors(['password' => $message]);
        }

        return $this->signIn($request, $user);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('staff.login');
    }

    private function signIn(Request $request, User $user): RedirectResponse
    {
        abort_unless(WebAdminAccess::allows($user), 403);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->update(['last_login_at' => now()]);

        return redirect()->intended(route('staff.dashboard'));
    }
}
