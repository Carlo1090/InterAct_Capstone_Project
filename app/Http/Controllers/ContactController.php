<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContactCoordinatorRequest;
use App\Mail\CoordinatorContactMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ContactController extends Controller
{
    /**
     * Take a message from the landing page's public contact form.
     *
     * THE COORDINATOR'S ADDRESS NEVER REACHES THE CLIENT. It is read from
     * config here and handed straight to the transport; the response says only
     * whether the message was accepted. Returning it — or echoing it in an
     * error — would publish a staff mailbox on an unauthenticated endpoint for
     * anyone who wanted to scrape it.
     */
    public function store(ContactCoordinatorRequest $request): JsonResponse
    {
        $recipient = trim((string) config('mail.coordinator_address'));

        /*
         * An unconfigured deployment is a 503, not a silent success. Reporting
         * "sent" when nothing was addressed would leave a student believing
         * somebody had been told, which is the worse of the two failures.
         */
        if ($recipient === '' || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            Log::warning('Contact form submitted but MDC_COORDINATOR_EMAIL is unset or invalid.');

            return response()->json([
                'message' => 'The contact form is not configured yet. Please email your coordinator directly.',
            ], 503);
        }

        $validated = $request->validated();

        try {
            Mail::to($recipient)->send(new CoordinatorContactMessage(
                senderName: $validated['name'],
                senderEmail: $validated['email'],
                body: $validated['message'],
            ));
        } catch (Throwable $e) {
            /*
             * The exception text can carry the SMTP conversation, and that names
             * the recipient — so it goes to the log and never to the response.
             */
            Log::error('Contact form delivery failed.', ['exception' => $e]);

            return response()->json([
                'message' => 'We could not send your message just now. Please try again shortly.',
            ], 502);
        }

        return response()->json([
            'message' => 'Thanks — your message has been sent to the OJT coordinator.',
        ]);
    }
}
