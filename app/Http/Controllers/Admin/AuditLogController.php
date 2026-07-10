<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $logs = $this->filtered($request)
            ->orderByDesc('logged_at')
            ->paginate(20);

        return response()->json($logs);
    }

    public function actions(): JsonResponse
    {
        return response()->json(
            SystemLog::query()->distinct()->orderBy('action')->pluck('action')
        );
    }

    public function export(Request $request): StreamedResponse
    {
        $logs = $this->filtered($request)->orderByDesc('logged_at')->get();

        return response()->streamDownload(function () use ($logs) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Timestamp', 'User', 'Role', 'Action', 'Details', 'IP Address']);

            foreach ($logs as $log) {
                fputcsv($handle, [
                    $log->logged_at,
                    $log->user->name ?? 'Unknown',
                    $log->user->role ?? '',
                    $log->action,
                    $log->description,
                    $log->ip_address,
                ]);
            }

            fclose($handle);
        }, 'audit-logs.csv', ['Content-Type' => 'text/csv']);
    }

    private function filtered(Request $request): Builder
    {
        return SystemLog::with('user:id,name,role')
            ->when(
                $request->filled('search'),
                fn ($query) => $query->where(
                    fn ($q) => $q
                        ->where('description', 'like', '%'.$request->string('search').'%')
                        ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', '%'.$request->string('search').'%'))
                )
            )
            ->when($request->filled('action'), fn ($query) => $query->where('action', $request->string('action')))
            ->when(
                $request->filled('role'),
                fn ($query) => $query->whereHas('user', fn ($uq) => $uq->where('role', $request->string('role')))
            )
            ->when($request->filled('date'), fn ($query) => $query->whereDate('logged_at', $request->string('date')));
    }
}
