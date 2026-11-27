<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\Management\Authorizations;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Lo de la tienda que Dirección autoriza desde la web: precios y ofertas. Usa
 * los mismos servicios que la app; el bono y las comisiones siguen en la app.
 */
class AuthorizationController extends Controller
{
    public const TYPES = 'price_change|promotion';

    public function index(): View
    {
        return view('staff.authorizations.index', [
            'items' => array_values(array_filter(
                Authorizations::pending(),
                fn (array $item) => in_array($item['type'], explode('|', self::TYPES), true),
            )),
            'unpublished' => DB::table('products')->where('is_active', false)->count(),
        ]);
    }

    public function show(string $type, string $id): View
    {
        return view('staff.authorizations.show', [
            'type' => $type,
            'item' => Authorizations::of($type)->detail($id),
            'id' => $id,
        ]);
    }

    public function approve(Request $request, string $type, string $id): RedirectResponse
    {
        Authorizations::of($type)->approve($request->user(), $id);

        return to_route('staff.authorizations.index')->with('status', 'Autorizado.');
    }

    public function sendBack(Request $request, string $type, string $id): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], [], ['reason' => 'el motivo']);

        Authorizations::of($type)->sendBack($request->user(), $id, trim($data['reason']));

        return to_route('staff.authorizations.index')->with('status', 'Devuelto con el motivo.');
    }
}
