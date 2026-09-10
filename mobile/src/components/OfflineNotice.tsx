import { Banner } from './Banner';
import { OfflineFeature, OfflineLevel, offlineLevelFor, offlineNoteFor } from '../lib/offlineCapability';

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
 */
export function OfflineNotice({ feature, show = true }: { feature: OfflineFeature; show?: boolean }) {
  if (!show) return null;

  return (
    <Banner variant="offline" title={OFFLINE_TITLE[offlineLevelFor(feature)]}>
      {offlineNoteFor(feature)}
    </Banner>
  );
}
