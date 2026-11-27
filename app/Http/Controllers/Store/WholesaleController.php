<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\QuoteRequestForm;
use App\Services\Store\WholesaleCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WholesaleController extends Controller
{
    public function show(WholesaleCatalog $wholesale): View
    {
        return view('store.wholesale', [
            'tables' => $wholesale->tables(),
            'example' => $wholesale->example(),
            'popular' => $wholesale->popular(),
        ]);
    }

    public function quote(QuoteRequestForm $request): RedirectResponse
    {
        // A un robot se le responde igual, pero no se guarda nada.
        if (! $request->isSpam()) {
            DB::table('quote_requests')->insert([
                'id_quote_request' => (string) Str::uuid7(),
                'name' => Str::squish($request->validated('name')),
                'phone' => Str::squish($request->validated('phone')),
                'message' => trim($request->validated('message')),
            ]);
        }

        return to_route('store.wholesale.show')
            ->withFragment('cotizacion')
            ->with('status', 'Recibimos tu pedido de cotización. Te escribiremos por WhatsApp.');
    }
}
