<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BatchStudentPurgeService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BatchStudentPurgeController extends Controller
{
    /**
     * Demo/manual trigger for the same purge the nightly 02:00 schedule
     * runs — lets an admin click through it live rather than waiting for
     * the schedule or for records to actually age past 30 days. An admin
     * may pick any "now" for demo flexibility, mirroring
     * WeeklyBundlingController::run()'s optional week_start.
     */
    public function run(Request $request, BatchStudentPurgeService $service): JsonResponse
    {
        $validated = $request->validate([
            'now' => ['nullable', 'date'],
        ]);

        $now = isset($validated['now']) ? Carbon::parse($validated['now']) : null;

        return response()->json($service->purgeExpiredArchives($now));
    }
}
