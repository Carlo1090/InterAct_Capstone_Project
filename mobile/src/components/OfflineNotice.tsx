import { Banner } from './Banner';
import { OfflineFeature, OfflineLevel, offlineLevelFor, offlineNoteFor } from '../lib/offlineCapability';
import { ApiError } from '../services/api';

/**
 * One title per level, and the three are genuinely different facts rather than
 * three phrasings of one — which is why the title carries the distinction and
 * the loud styling stays constant.
 *
 *   read_write  — nothing is lost; keep working.
 *   read        — what is on screen may be stale.
 *   online_only — the feature does not work at all right now. Saying "showing
 *                 saved data" here would be a lie: on the Scan tab there is no
 *                 saved punch to show, and a student who reads it as merely
 *                 stale will stand at the door pressing a button that cannot
 *                 work.
 */
const OFFLINE_TITLE: Record<OfflineLevel, string> = {
  read_write: "Offline — your work is safe",
  read: 'Offline — showing saved data',
  online_only: "Offline — this needs a connection",
};

/**
 * The one offline notice. Every screen renders this rather than writing its
 * own wording, so a student never gets two different explanations of the
 * same state — and so the read vs read-write distinction is stated in the UI
 * rather than only in the code.
 *
 * BOTH LEVELS NOW USE THE LOUD `offline` VARIANT (2026-09-09, project owner:
 * "the warning signs are not kinda highlight"). Read-only screens used to
 * render this as `neutral` — grey on grey, the quietest style in the app — so
 * the notice telling a student their data might be stale was less visible than
 * the ordinary blue tips beside it.
 *
 * The TITLE is what still separates the two levels, and it carries the part
 * that actually matters to the student: whether what they are about to do will
 * survive. On a read-write screen offline is a supported state and the title
 * says so; on a read-only screen it is a real limitation.
 *
 * `error` OVERRIDES BOTH LINES WHEN IT WAS A TIMEOUT, NOT A DEAD CONNECTION
 * (2026-09-10, found from a real report: "im currently on offline even thu i
 * have internet connections"). Every screen fed this component from an
 * `isOffline` flag that fires on ANY failed request — and the API sleeps on a
 * free Render instance, taking up to a minute to wake, so a student opening
 * the app right as it woke up got told "check your internet connection" for a
 * problem that was never theirs. `ApiError.isTimeout` is the one signal that
 * already distinguished the two cases (Axios `ECONNABORTED`/`ETIMEDOUT` vs a
 * genuine no-response failure) — it just never reached this component.
 * `isOffline` itself is left alone (still gates writes, still falls back to
 * cache exactly as before); only what is SAID here changes.
 */
export function OfflineNotice({
  feature,
  show = true,
  error,
}: {
  feature: OfflineFeature;
  show?: boolean;
  error?: ApiError | null;
}) {
  if (!show) return null;

  if (error?.isTimeout) {
    return (
      <Banner variant="offline" title="Reconnecting — this may take a moment">
        {error.message}
      </Banner>
    );
  }

  return (
    <Banner variant="offline" title={OFFLINE_TITLE[offlineLevelFor(feature)]}>
      {offlineNoteFor(feature)}
    </Banner>
  );
}
