import { Banner } from './Banner';
import { OfflineFeature, offlineLevelFor, offlineNoteFor } from '../lib/offlineCapability';

/**
 * The one offline notice. Every screen renders this rather than writing its
 * own wording, so a student never gets two different explanations of the
 * same state — and so the read vs read-write distinction is stated in the UI
 * rather than only in the code.
 *
 * `read_write` uses the informational tone because offline is a normal,
 * supported state there, not a degradation.
 */
export function OfflineNotice({ feature, show = true }: { feature: OfflineFeature; show?: boolean }) {
  if (!show) return null;

  return (
    <Banner variant={offlineLevelFor(feature) === 'read_write' ? 'info' : 'neutral'}>
      {offlineNoteFor(feature)}
    </Banner>
  );
}
