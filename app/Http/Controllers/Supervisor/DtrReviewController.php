<?php

namespace App\Http\Controllers\Supervisor;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Supervisor\Concerns\ScopesSupervisorWork;
use App\Http\Requests\Supervisor\AdjustDtrSessionRequest;
use App\Models\DtrSession;
use App\Models\SystemLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The supervisor's review of what the geofence actually recorded.
 *
 * This exists because the automated half of the DTR cannot be trusted on its
 * own — browser geolocation is client-supplied, and a student who forgets to
 * scan out leaves a session with no end. Every punch stores its coordinates,
 * reported accuracy and computed distance so the person who was actually
 * there can sanity-check it, and every correction is recorded with a reason.
 */
class DtrReviewController extends Controller
{
    use ScopesSupervisorWork;

    /**
     * Sessions for this supervisor's interns, newest first. Defaults to
     * everything needing attention (open past its day, or flagged), since
     * that is the only part of the log that needs a human.
     */
    public function index(Request $request): JsonResponse
    {
        $supervisor = $request->user();
        $studentIds = $this->supervisedStudentIds($supervisor);

        $filter = $request->query('status', 'needs_attention');

        $query = DtrSession::with(['student:id,name', 'geofence:id,label'])
            ->whereIn('student_id', $studentIds);

        if ($filter === 'needs_attention') {
            $query->where(function ($q) {
                $q->where('status', 'flagged')
                    // An open session from a previous day is a forgotten
                    // clock-out; one from today is simply someone at work.
                    ->orWhere(fn ($inner) => $inner->where('status', 'open')
                        ->whereDate('work_date', '<', now()->toDateString()));
            });
        } elseif (in_array($filter, ['open', 'closed', 'flagged', 'void'], true)) {
            $query->where('status', $filter);
        }

        if ($studentId = $request->query('student_id')) {
            $query->where('student_id', $studentId);
        }

        $sessions = $query->orderByDesc('time_in')->paginate(25);

        $sessions->getCollection()->transform(fn (DtrSession $session) => $this->present($session));

        return response()->json($sessions);
    }

    /**
     * Close (or correct) a session by hand. The canonical use is a student
     * who scanned in and went home without scanning out.
     *
     * minutes_worked is written directly rather than being back-computed from
     * an invented time_out: the supervisor knows how long the intern worked,
     * not what minute they walked out, and fabricating a precise departure
     * timestamp would put a number on the record nobody observed.
     */
    public function adjust(AdjustDtrSessionRequest $request, DtrSession $session): JsonResponse
    {
        $this->authorizeSession($request, $session);

        abort_if(
            $session->status === 'void',
            422,
            'This session has been voided. Voided sessions cannot be adjusted.'
        );

        $validated = $request->validated();

        $session->fill([
            'minutes_worked' => $validated['minutes_worked'],
            'status' => 'closed',
            'adjusted_by' => $request->user()->id,
            'adjustment_reason' => $validated['reason'],
        ]);

        $session->save();

        SystemLog::record(
            'DTR Session Adjusted',
            "Set {$session->student?->name}'s {$session->work_date?->toDateString()} session to {$validated['minutes_worked']} minutes: {$validated['reason']}"
        );

        return response()->json([
            'session' => $this->present($session->fresh(['student', 'geofence'])),
            'message' => 'Session adjusted.',
        ]);
    }

    /**
     * Discount a session entirely without deleting it. Voided minutes stop
     * counting toward required hours but the row stays on the record, which
     * is the point — a discarded punch that leaves no trace is indistinguishable
     * from one that never happened.
     */
    public function void(Request $request, DtrSession $session): JsonResponse
    {
        $this->authorizeSession($request, $session);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $session->fill([
            'status' => 'void',
            'adjusted_by' => $request->user()->id,
            'adjustment_reason' => $validated['reason'],
        ]);

        $session->save();

        SystemLog::record(
            'DTR Session Voided',
            "Voided {$session->student?->name}'s {$session->work_date?->toDateString()} session: {$validated['reason']}"
        );

        return response()->json([
            'session' => $this->present($session->fresh(['student', 'geofence'])),
            'message' => 'Session voided. It no longer counts toward required hours.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DtrSession $session): array
    {
        return [
            'id' => $session->id,
            'student_id' => $session->student_id,
            'student_name' => $session->student?->name,
            'site' => $session->geofence?->label,
            'work_date' => $session->work_date?->toDateString(),
            'time_in' => $session->time_in?->toIso8601String(),
            'time_out' => $session->time_out?->toIso8601String(),
            'minutes_worked' => $session->minutes_worked,
            'status' => $session->status,
            // The audit trail. A punch taken at the edge of the radius with a
            // 400m accuracy reading is technically inside the fence and still
            // worth a second look.
            'time_in_distance' => $session->time_in_distance,
            'time_in_accuracy' => $session->time_in_accuracy,
            'time_out_distance' => $session->time_out_distance,
            'time_out_accuracy' => $session->time_out_accuracy,
            'adjustment_reason' => $session->adjustment_reason,
            'adjusted_by' => $session->adjuster?->name,
        ];
    }

    private function authorizeSession(Request $request, DtrSession $session): void
    {
        abort_unless(
            $this->supervisedStudentIds($request->user())->contains($session->student_id),
            403,
            'That time record is not for one of your interns.'
        );
    }
}
