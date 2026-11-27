<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Las cotizaciones que se piden desde la tienda. */
class QuoteController extends Controller
{
    public const STATUSES = ['new' => 'Nuevas', 'answered' => 'Respondidas', 'closed' => 'Cerradas'];

    public function index(Request $request): View
    {
        $status = $request->query('estado');
        $status = is_string($status) && isset(self::STATUSES[$status]) ? $status : 'new';

        return view('staff.quotes', [
            'status' => $status,
            'statuses' => self::STATUSES,
            'quotes' => DB::table('quote_requests as q')
                ->leftJoin('users as u', 'u.id_user', '=', 'q.assigned_to')
                ->where('q.status', $status)
                ->orderByDesc('q.created_at')
                ->select(['q.*', 'u.name as assigned_name'])
                ->paginate(20)
                ->withQueryString(),
        ]);
    }

    public function update(Request $request, string $quote): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['answered', 'closed'])]]);

        $updated = DB::table('quote_requests')->where('id_quote_request', $quote)->update([
            'status' => $data['status'],
            'assigned_to' => $request->user()->getKey(),
            ...($data['status'] === 'answered' ? ['answered_at' => now()] : []),
        ]);

        abort_if($updated === 0, 404);

        return back()->with('status', 'Cotización actualizada.');
    }
}
