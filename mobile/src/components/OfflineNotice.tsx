import { Banner } from './Banner';
import { OfflineFeature, offlineLevelFor, offlineNoteFor } from '../lib/offlineCapability';

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

  const writable = offlineLevelFor(feature) === 'read_write';

  return (
    <Banner variant="offline" title={writable ? "Offline — your work is safe" : 'Offline — showing saved data'}>
      {offlineNoteFor(feature)}
    </Banner>
  );
}
