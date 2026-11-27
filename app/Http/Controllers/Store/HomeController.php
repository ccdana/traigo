<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\Store\GiftCalendar;
use App\Services\Store\StoreCatalog;
use App\Services\Store\StoreSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function __invoke(
        Request $request,
        StoreCatalog $catalog,
        GiftCalendar $calendar,
        StoreSettings $settings,
    ): View {
        // Los filtros vienen en la dirección: un valor raro se ignora.
        $budget = (int) $request->query('presupuesto');
        $budget = in_array($budget, StoreCatalog::GIFT_BUDGETS, true) ? $budget : null;

        $audiences = $catalog->giftAudiences();
        $audience = $request->query('para');
        $audience = is_string($audience) && isset($audiences[$audience]) ? $audience : null;

        return view('store.home', [
            'gifts' => $catalog->gifts($budget, $audience),
            'budget' => $budget,
            'audience' => $audience,
            'audiences' => $audiences,
            'doors' => $calendar->doors(),
            'categories' => $catalog->categories(),
            'wholesaleMinimums' => $catalog->wholesaleMinimums(),
            'countdown' => $settings->countdown(),
        ]);
    }
}
