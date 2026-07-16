<?php

namespace App\Http\Controllers\Coordinator;

use App\Http\Controllers\Controller;
use App\Http\Requests\Coordinator\AddRosterStudentRequest;
use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\User;
use App\Services\EnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Batch roster management for coordinators: view a batch's interns and
 * add / move / remove / delete rows. Everything is authorized against the
 * coordinator's department programs (out-of-scope batch or student -> 403),
 * the same scope EnrollmentController's Users lists use.
 */
class BatchRosterController extends Controller
{
    /**
     * All roster rows for a batch (active first, then completed, then dropped)
     * so the UI can offer Remove on active rows and Delete on dropped ones.
     */
    public function interns(Request $request, Batch $batch): JsonResponse
    {
        $this->authorizeBatch($request->user(), $batch);

        $students = BatchStudent::where('batch_id', $batch->id)
            ->with(['student:id,name,email,student_id_number', 'company:id,name', 'supervisor:id,name,email'])
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'completed' THEN 1 ELSE 2 END")
            ->orderByDesc('enrolled_at')
            ->get();

        return response()->json([
            'batch' => $batch->only(['id', 'name', 'program_id']),
            'students' => $students,
        ]);
    }

    /**
     * Add a student to the batch. Rules:
     *  - the student's program must match the batch's program (422 otherwise);
     *  - if the student is already active in ANOTHER batch, MOVE them (drop the
     *    old row, create the new active one) and flag moved=true;
     *  - if already active in THIS batch, 422;
     *  - otherwise just enroll them active (moved=false).
     */
    public function add(AddRosterStudentRequest $request, Batch $batch, EnrollmentService $enrollments): JsonResponse
    {
        $coordinator = $request->user();
        $this->authorizeBatch($coordinator, $batch);

        $student = User::where('role', 'student')->findOrFail($request->integer('student_id'));

        abort_unless(
            $coordinator->coordinatorProgramIds()->contains($student->program_id),
            403,
            'That student is outside your assigned department(s).'
        );

        if ((int) $student->program_id !== (int) $batch->program_id) {
            return response()->json([
                'message' => "This student's program does not match this batch's program. You can only move a student within the same program.",
                'errors' => ['student_id' => ["This student's program does not match this batch's program."]],
            ], 422);
        }

        $currentActive = BatchStudent::where('student_id', $student->id)
            ->where('status', 'active')
            ->first();

        if ($currentActive && (int) $currentActive->batch_id === (int) $batch->id) {
            return response()->json([
                'message' => 'This student is already active in this batch.',
                'errors' => ['student_id' => ['This student is already active in this batch.']],
            ], 422);
        }

        $moved = false;

        if ($currentActive) {
            $currentActive->update(['status' => 'dropped']);
            $moved = true;
        }

        // Through the shared service so a prior dropped/completed row for
        // this exact (batch, student) pair is reconciled in place, never
        // duplicated (the DB unique index would reject a second row).
        $enrollment = $enrollments->enrollOrReactivate(
            $batch->id,
            $student->id,
            $request->integer('company_id'),
            $request->input('assigned_division'),
        );

        return response()->json([
            'moved' => $moved,
            'enrollment' => $enrollment->load(['student:id,name,email,student_id_number', 'company:id,name', 'supervisor:id,name,email']),
        ], 201);
    }

    /**
     * Remove an intern from the batch: mark the row 'dropped' (keep history).
     */
    public function remove(Request $request, Batch $batch, BatchStudent $batchStudent): JsonResponse
    {
        $this->authorizeBatch($request->user(), $batch);
        $this->assertRowInBatch($batch, $batchStudent);

        $batchStudent->update(['status' => 'dropped']);

        return response()->json($batchStudent->fresh(['student:id,name,email,student_id_number', 'company:id,name', 'supervisor:id,name,email']));
    }

    /**
     * Delete a roster row entirely — only allowed once it has been archived.
     * This fully subsumes the old "not active" guard, since archive() itself
     * already refuses an active row.
     */
    public function destroy(Request $request, Batch $batch, BatchStudent $batchStudent): JsonResponse
    {
        $this->authorizeBatch($request->user(), $batch);
        $this->assertRowInBatch($batch, $batchStudent);

        abort_if($batchStudent->archived_at === null, 422, 'Archive this record before deleting it permanently.');

        $batchStudent->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Move a dropped or completed row into the archive — reversible via
     * restore(), and permanently purged by BatchStudentPurgeService after
     * 30 days if left archived. Not allowed on an active row. archived_at
     * is deliberately excluded from the model's fillable list, so it's set
     * via direct property assignment rather than update(), which would
     * silently no-op.
     */
    public function archive(Request $request, Batch $batch, BatchStudent $batchStudent): JsonResponse
    {
        $this->authorizeBatch($request->user(), $batch);
        $this->assertRowInBatch($batch, $batchStudent);

        abort_if($batchStudent->status === 'active', 422, 'Drop or mark this intern completed before archiving the record.');
        abort_if($batchStudent->archived_at !== null, 422, 'This record is already archived.');

        $batchStudent->archived_at = now();
        $batchStudent->save();

        return response()->json($batchStudent->fresh(['student:id,name,email,student_id_number', 'company:id,name', 'supervisor:id,name,email']));
    }

    /**
     * Undo an archive: clears archived_at, leaving status untouched — a
     * restored row lands back in whichever of Dropped/Completed it came
     * from, since archiving never changes status.
     */
    public function restore(Request $request, Batch $batch, BatchStudent $batchStudent): JsonResponse
    {
        $this->authorizeBatch($request->user(), $batch);
        $this->assertRowInBatch($batch, $batchStudent);

        abort_if($batchStudent->archived_at === null, 422, 'This record is not archived.');

        $batchStudent->archived_at = null;
        $batchStudent->save();

        return response()->json($batchStudent->fresh(['student:id,name,email,student_id_number', 'company:id,name', 'supervisor:id,name,email']));
    }

    /**
     * Undo a drop: flip a 'dropped' row back to 'active' in place (same
     * company/supervisor/division it had before), rather than re-adding via
     * the Add-Intern form. Blocked if the student already has an active row
     * elsewhere — a student may only have one active enrollment at a time
     * (the same constraint StoreEnrollmentRequest enforces), so reactivating
     * here would collide with it.
     */
    public function reactivate(Request $request, Batch $batch, BatchStudent $batchStudent): JsonResponse
    {
        $this->authorizeBatch($request->user(), $batch);
        $this->assertRowInBatch($batch, $batchStudent);

        abort_unless($batchStudent->status === 'dropped', 422, 'Only a dropped record can be reactivated.');
        abort_if($batchStudent->archived_at !== null, 422, 'Restore this record from the archive before reactivating it.');

        $activeElsewhere = BatchStudent::where('student_id', $batchStudent->student_id)
            ->where('status', 'active')
            ->with('batch:id,name')
            ->first();

        if ($activeElsewhere) {
            return response()->json([
                'message' => "This student is already active in \"{$activeElsewhere->batch?->name}\". Use Add or Move on that batch instead of reactivating this dropped record.",
            ], 422);
        }

        $batchStudent->update(['status' => 'active']);

        return response()->json($batchStudent->fresh(['student:id,name,email,student_id_number', 'company:id,name', 'supervisor:id,name,email']));
    }

    /**
     * Mark an active intern's OJT as completed. The BatchStudent saving
     * hook stamps completed_at, which freezes the student's real-time
     * journal window at the completion date: reads stay open, writes and
     * dates after completion lock, and the weekly bundler skips them.
     */
    public function complete(Request $request, Batch $batch, BatchStudent $batchStudent): JsonResponse
    {
        $this->authorizeBatch($request->user(), $batch);
        $this->assertRowInBatch($batch, $batchStudent);

        abort_unless($batchStudent->status === 'active', 422, 'Only an active intern can be marked completed.');

        $batchStudent->update(['status' => 'completed']);

        return response()->json($batchStudent->fresh(['student:id,name,email,student_id_number', 'company:id,name', 'supervisor:id,name,email']));
    }

    /**
     * Undo a completion: flip the row back to 'active' (the saving hook
     * clears completed_at), reopening the student's journal window.
     * Blocked if the student already has an active enrollment elsewhere —
     * same one-active-enrollment constraint reactivate() enforces.
     */
    public function reopen(Request $request, Batch $batch, BatchStudent $batchStudent): JsonResponse
    {
        $this->authorizeBatch($request->user(), $batch);
        $this->assertRowInBatch($batch, $batchStudent);

        abort_unless($batchStudent->status === 'completed', 422, 'Only a completed record can be reopened.');
        abort_if($batchStudent->archived_at !== null, 422, 'Restore this record from the archive before reopening it.');

        $activeElsewhere = BatchStudent::where('student_id', $batchStudent->student_id)
            ->where('status', 'active')
            ->with('batch:id,name')
            ->first();

        if ($activeElsewhere) {
            return response()->json([
                'message' => "This student is already active in \"{$activeElsewhere->batch?->name}\". Resolve that enrollment before reopening this completed record.",
            ], 422);
        }

        $batchStudent->update(['status' => 'active']);

        return response()->json($batchStudent->fresh(['student:id,name,email,student_id_number', 'company:id,name', 'supervisor:id,name,email']));
    }

    private function authorizeBatch(User $coordinator, Batch $batch): void
    {
        abort_unless(
            $coordinator->coordinatorProgramIds()->contains($batch->program_id),
            403,
            'You do not have access to this batch.'
        );
    }

    private function assertRowInBatch(Batch $batch, BatchStudent $batchStudent): void
    {
        abort_unless((int) $batchStudent->batch_id === (int) $batch->id, 404);
    }
}
