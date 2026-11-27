<?php

namespace App\View\Composers;

use App\Services\Store\Bag;
use App\Services\Store\StoreCatalog;
use App\Services\Store\StoreSettings;
use App\Support\Dates;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** Lo que comparten el encabezado y el pie de todas las páginas de la tienda. */
class StoreLayoutComposer
{
    public function __construct(
        private readonly StoreCatalog $catalog,
        private readonly StoreSettings $settings,
        private readonly Bag $bag,
    ) {}

    public function compose(View $view): void
    {
        $deadline = $this->settings->get('christmas_order_deadline');
        $date = is_string($deadline) ? strtotime($deadline) : false;

        $customer = Auth::guard('customer')->user();

        $view->with([
            'customerName' => $customer?->isActiveCustomer() ? $customer->name : null,
            'navCategories' => $this->catalog->categories(),
            'bagCount' => $this->bag->count(),
            'whatsappNumber' => $this->settings->get('store_whatsapp'),
            'whatsappUrl' => $this->settings->whatsappUrl(),
            'tiktok' => $this->settings->get('store_tiktok'),
            'pickupAddress' => $this->settings->get('pickup_address'),
            'deadlineLabel' => $date === false ? null : Dates::short(date('Y-m-d', $date)),
        ]);
    }
}
