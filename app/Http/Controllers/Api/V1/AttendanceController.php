<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Attendance\Attendance;
use App\Support\ClientKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AttendanceController
{
    public function index(Request $request, Attendance $attendance): JsonResponse
    {
        return response()->json(['data' => $attendance->status($request->user())])
            ->header('Cache-Control', 'private, no-store');
    }

    public function configure(Request $request, Attendance $attendance): JsonResponse
    {
        $point = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-85,85'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return response()->json(['data' => $attendance->configure(
            $request->user(), (float) $point['latitude'], (float) $point['longitude'],
        )])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Attendance $attendance): JsonResponse
    {
        $key = ClientKey::require($request);
        $input = $request->validate([
            'kind' => ['required', 'in:entry,exit'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'captured_at' => ['bail', 'required', 'string', 'max:35', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D'],
        ]);

        return response()->json(['data' => $attendance->mark($request->user(), $key, $input)])
            ->header('Cache-Control', 'private, no-store');
    }
}
