<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\NewSaleRequest;
use App\Http\Resources\SaleOrder;
use App\Models\Order;
use App\Services\Sales\CancelSale;
use App\Services\Sales\CreateSale;
use App\Services\Sales\OwnOrders;
use App\Services\Sales\SubmitProof;
use App\Services\Team\TeamHome;
use App\Support\ClientKey;
use App\Support\Responses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Las ventas de quien vende: solo las propias. */
class OrderController extends Controller
{
    public const PER_PAGE = 20;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending_payment', 'payment_review', 'paid', 'delivered'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Order::query()
            ->with(['items', 'currentPayment'])
            ->where('seller_id', $request->user()->getKey());

        if (filled($filters['status'] ?? null)) {
            // «Pagados» incluye los que ya están listos para entregar.
            $query->whereIn('status', $filters['status'] === 'paid' ? ['paid', 'ready'] : [$filters['status']]);
        }

        $page = $query->orderByDesc('created_at')->orderByDesc('id_order')->paginate(self::PER_PAGE);

        return Responses::paginated($page, SaleOrder::summary(...));
    }

    public function show(Request $request, string $order): JsonResponse
    {
        return $this->detail(OwnOrders::find($request->user(), $order));
    }

    public function commissions(Request $request): JsonResponse
    {
        return response()->json(['data' => TeamHome::monthCommission($request->user())])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(NewSaleRequest $request, CreateSale $sales): JsonResponse
    {
        $order = $sales->create($request->user(), $request->validated(), ClientKey::require($request));

        return $this->detail($order, 201);
    }

    public function cancel(Request $request, string $order, CancelSale $sales): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);

        return $this->detail($sales->cancel($request->user(), $order, $data['reason'] ?? null));
    }

    public function paymentProof(Request $request, string $order, SubmitProof $proofs): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'reference' => ['nullable', 'string', 'max:60'],
        ], [
            'file.required' => 'Falta la foto del comprobante.',
            'file.mimes' => 'Solo fotos JPG o PNG, o un PDF.',
            'file.max' => 'El archivo no puede pesar más de 5 MB.',
            'file.uploaded' => 'No se pudo subir el archivo (máximo 5 MB).',
        ]);

        return $this->detail($proofs->submit(
            $request->user(),
            $order,
            $request->file('file'),
            $data['reference'] ?? null,
            ClientKey::require($request),
        ));
    }

    private function detail(Order $order, int $status = 200): JsonResponse
    {
        return response()->json(['data' => SaleOrder::detail($order->fresh(SaleOrder::DETAIL_RELATIONS))], $status);
    }
}
