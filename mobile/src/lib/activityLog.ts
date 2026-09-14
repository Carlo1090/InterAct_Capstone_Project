import { Ionicons } from '@expo/vector-icons';
import { colors } from '../constants/colors';
import { SystemLogEntry } from '../types/api';

/**
 * ONE definition of how an audit row is described to a student, shared by the
 * full Activity Log and the dashboard's Recent Activity card.
 *
 * THE BUG THIS FIXES: these two screens read the same five SystemLog rows and
 * described them completely differently. The dashboard printed the raw
 * `description` — the audit table's own wording, with the student's own name
 * on the front of nearly every line ("Juan Dela Cruz logged in (mobile)") and
 * a bare coloured dot; the Activity Log showed "Signed in" with an icon, a
 * category colour and the name trimmed. The same event read as two different
 * events depending on which screen you were on.
 *
 * Same reasoning as the backend's `EnrollmentService` and
 * `ReviewsWeeklyJournals`: a student's own history must not mean two different
 * things depending on where it is being read.
 */

export type ActivityCategory = 'session' | 'time' | 'submission' | 'account' | 'other';

export const CATEGORY: Record<ActivityCategory, { icon: keyof typeof Ionicons.glyphMap; tint: string; wash: string }> = {
  // Deliberately the quietest treatment: sign-ins are the most frequent rows
  // and the least interesting, so they must not dominate the page.
  session: { icon: 'log-in-outline', tint: colors.gray500, wash: colors.gray100 },
  time: { icon: 'time-outline', tint: colors.blue600, wash: colors.blue50 },
  submission: { icon: 'checkmark-circle-outline', tint: colors.greenTx, wash: colors.greenBg },
  account: { icon: 'person-circle-outline', tint: colors.warnTx, wash: colors.warnBg },
  other: { icon: 'ellipse-outline', tint: colors.gray500, wash: colors.gray100 },
};

/**
 * The server's action strings are audit-table vocabulary. This is the
 * student-facing wording, written in the first person because it is their own
 * history. Anything unmapped falls through to the raw action rather than
 * being hidden — an unexplained row is better than a missing one.
 */
export const ACTIONS: Record<string, { title: string; category: ActivityCategory; icon?: keyof typeof Ionicons.glyphMap }> = {
  'Logged In': { title: 'Signed in', category: 'session' },
  'Logged Out': { title: 'Signed out', category: 'session', icon: 'log-out-outline' },
  'DTR Clocked In': { title: 'Clocked in', category: 'time', icon: 'enter-outline' },
  'DTR Clocked Out': { title: 'Clocked out', category: 'time', icon: 'exit-outline' },
  'Daily Journal Submitted': { title: 'Submitted a daily journal', category: 'submission' },
  'Weekly Journal Submitted': { title: 'Submitted a weekly journal', category: 'submission' },
  'Weekly Journal Approved': { title: 'Weekly journal approved', category: 'submission' },
  'Weekly Journal Returned': {
    title: 'Weekly journal returned for changes',
    category: 'submission',
    icon: 'arrow-undo-outline',
  },
  'Exit Interview Submitted': { title: 'Submitted your exit interview', category: 'submission' },
  'Info Sheet Accepted': { title: 'Information sheet accepted', category: 'submission' },
  'Info Sheet Rejected': {
    title: 'Information sheet returned for changes',
    category: 'submission',
    icon: 'arrow-undo-outline',
  },
  'Profile Updated': { title: 'Updated your profile', category: 'account' },
  'Profile Photo Updated': { title: 'Changed your profile photo', category: 'account', icon: 'image-outline' },
  'Profile Photo Removed': { title: 'Removed your profile photo', category: 'account', icon: 'image-outline' },
  'Password Changed': { title: 'Changed your password', category: 'account', icon: 'key-outline' },
  'Temporary Password Issued': { title: 'A temporary password was issued', category: 'account', icon: 'key-outline' },
  'Credentials Resent': { title: 'Your login details were re-sent', category: 'account', icon: 'mail-outline' },
};

export function describeActivity(entry: SystemLogEntry) {
  const mapped = ACTIONS[entry.action];
  const category: ActivityCategory = mapped?.category ?? 'other';
  const base = CATEGORY[category];
  return {
    title: mapped?.title ?? entry.action,
    icon: mapped?.icon ?? base.icon,
    tint: base.tint,
    wash: base.wash,
  };
}

/**
 * The description repeats the student's own name on nearly every row
 * ("Juan Dela Cruz logged in (mobile)"). In a personal timeline that is noise
 * on every single line, so a leading own-name is trimmed. Only an exact
 * prefix match is removed — anything else is left completely alone.
 */
export function trimOwnName(description: string | null, name: string | undefined): string | null {
  if (!description) return null;
  if (!name || !description.startsWith(name)) return description;

  const rest = description.slice(name.length).trimStart();
  if (rest.length === 0) return null;
  return rest.charAt(0).toUpperCase() + rest.slice(1);
}
