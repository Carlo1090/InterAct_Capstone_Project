<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BatchStudentPurgeService;
use Illuminate\Http\JsonResponse;

class BatchStudentPurgeController extends Controller
{
    /**
     * Demo/manual trigger for the same purge the nightly 02:00 schedule
     * runs — lets an admin click through it live rather than waiting for
     * the schedule or for records to actually age past 30 days.
     */
    public function run(BatchStudentPurgeService $service): JsonResponse
    {
        return response()->json($service->purgeExpiredArchives());
    }
}
