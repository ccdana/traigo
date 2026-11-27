<?php

namespace App\Http\Controllers\Staff;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\PinLogin;
use App\Services\Auth\TwoFactor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Ingreso al panel web. Usa las mismas reglas que la app: código y PIN con
 * bloqueo por intentos y, para Jime y Dirección, un código por correo.
 */
class LoginController extends Controller
{
    private const CHALLENGE = 'staff.two_factor';

    public function __construct(
        private readonly PinLogin $pinLogin,
        private readonly TwoFactor $twoFactor,
    ) {}

    public function create(): View|RedirectResponse
    {
        return Auth::check() ? to_route('staff.inventory.index') : view('staff.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(
            ['code' => ['required', 'string', 'max:20'], 'pin' => ['required', 'string', 'max:12']],
            [],
            ['code' => 'el código', 'pin' => 'el PIN'],
        );

        try {
            $user = $this->pinLogin->check($data['code'], $data['pin']);

            if (! $user->role->canUseInventoryPanel()) {
                return $this->failed($request, 'El panel web es para almacén, Jime y Dirección. Tu trabajo está en la app Traigo Equipo.');
            }

            if (! $user->role->requiresSecondStep()) {
                return $this->signIn($request, $user);
            }

            $request->session()->put(self::CHALLENGE, $this->twoFactor->start($user)->getKey());
        } catch (ApiException $e) {
            return $this->failed($request, $e->getMessage());
        }

        return to_route('staff.two-factor.create');
    }

    public function twoFactor(Request $request): View|RedirectResponse
    {
        return $request->session()->has(self::CHALLENGE) ? view('staff.two-factor') : to_route('staff.login');
    }

    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12']], [], ['code' => 'el código']);

        try {
            $user = $this->twoFactor->verify((string) $request->session()->get(self::CHALLENGE), $data['code']);
        } catch (ApiException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        $request->session()->forget(self::CHALLENGE);

        return $this->signIn($request, $user);
    }

    public function resend(Request $request): RedirectResponse
    {
        try {
            $challenge = $this->twoFactor->resend((string) $request->session()->get(self::CHALLENGE));
        } catch (ApiException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        $request->session()->put(self::CHALLENGE, $challenge->getKey());

        return back()->with('status', 'Te enviamos un código nuevo.');
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
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->update(['last_login_at' => now()]);

        return redirect()->intended(route('staff.inventory.index'));
    }

    private function failed(Request $request, string $message): RedirectResponse
    {
        return back()->withInput($request->only('code'))->withErrors(['code' => $message]);
    }
}
