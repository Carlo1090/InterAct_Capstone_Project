<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\WeeklyBundlingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WeeklyBundlingController extends Controller
{
    /**
     * Demo/manual trigger for the same compilation the Saturday-00:00
     * schedule runs — lets an admin click through it live rather than
     * waiting for the schedule. Defaults to the most recently completed
     * Mon-Fri; an admin may pick any week_start for demo flexibility.
     */
    public function run(Request $request, WeeklyBundlingService $service): JsonResponse
    {
        $validated = $request->validate([
            'week_start' => ['nullable', 'date'],
        ]);

        $weekStart = $validated['week_start'] ?? WeeklyBundlingService::mostRecentlyCompletedWeekStart();

        return response()->json($service->bundleWeek($weekStart));
    }
}
