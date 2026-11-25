<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Team\SubmitDeliverable;
use App\Services\Team\SubmitEvidence;
use App\Support\ClientKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Lo que envía cada quien: evidencia de su KPI y entregas de Sistemas. */
class SubmissionController extends Controller
{
    /** Las fotos son JPG o PNG de hasta 5 MB; no se adjuntan otros archivos. */
    private const PHOTO = ['file', 'mimes:jpg,jpeg,png', 'max:5120'];

    private const MESSAGES = [
        'file.required_if' => 'Falta la foto.',
        'file.mimes' => 'Solo fotos JPG o PNG.',
        'file.max' => 'La foto no puede pesar más de 5 MB.',
        'file.uploaded' => 'No se pudo subir la foto (máximo 5 MB).',
        'url.required_if' => 'Falta el enlace.',
        'url.required_without' => 'Adjunta una foto o un enlace.',
        'order_id.required_if' => 'Elige el pedido.',
        'delivered_on.before_or_equal' => 'La fecha real no puede ser futura.',
    ];

    public function evidence(Request $request, string $result, SubmitEvidence $evidence): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['photo', 'link', 'order'])],
            'file' => ['nullable', 'required_if:kind,photo', ...self::PHOTO],
            'url' => ['nullable', 'required_if:kind,link', 'string', 'max:2000'],
            'order_id' => ['nullable', 'required_if:kind,order', 'uuid'],
            'note' => ['nullable', 'string', 'max:500'],
        ], self::MESSAGES);

        $saved = $evidence->submit(
            $request->user(),
            $result,
            $data,
            $request->file('file'),
            ClientKey::require($request),
        );

        return response()->json(['data' => $saved->toApi()], 201);
    }

    public function deliverable(Request $request, string $deliverable, SubmitDeliverable $deliverables): JsonResponse
    {
        $data = $request->validate([
            'delivered_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.today()->toDateString()],
            'file' => ['nullable', ...self::PHOTO],
            'url' => ['nullable', 'required_without:file', 'string', 'max:2000'],
        ], self::MESSAGES);

        ClientKey::require($request);

        $saved = $deliverables->submit(
            $request->user(),
            $deliverable,
            $data['delivered_on'],
            $request->file('file'),
            $data['url'] ?? null,
        );

        return response()->json(['data' => $saved->toApi()]);
    }
}
