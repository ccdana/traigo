<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\Store\DeliverySearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeliverySearchController extends Controller
{
    public function __invoke(Request $request, DeliverySearch $search): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:3', 'max:200']]);
        return response()->json(['data' => $search->search($data['q'])]);
    }
}
