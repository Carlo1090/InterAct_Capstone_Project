import type { ReviewTemplateSection } from '@/types/api'

/**
 * How a reviewer reads one daily journal entry.
 *
 * ONE writer for every review surface — the supervisor's review modal, the
 * per-intern notebook (which the coordinator's Journal Review mounts too).
 * Same reasoning as `ReviewsWeeklyJournals` on the backend: a student's entry
 * must not read differently depending on which of the two people opened it.
 */

/** Humanise a raw content key: `task_performed` -> `Task Performed`. */
export function humanizeJournalKey(key: string): string {
  return key
    .split('_')
    .filter(Boolean)
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}

export type JournalContentField = {
  key: string
  label: string
  value: string
  /** True for the Annex C trio, so a reviewer can see the SIPP block as a block. */
  sipp: boolean
}

/**
 * The filled fields of one entry, IN THE COORDINATOR'S TEMPLATE ORDER and under
 * the coordinator's own wording.
 *
 * **This used to be a bare `Object.entries(content)`, and that was the bug.**
 * `journal_entries.content` is a JSON column, so iterating it yields whatever
 * order the student's payload happened to serialise in — which in practice put
 * the fixed `daily_accomplishment` section LAST, after the SIPP trio, with the
 * three SIPP fields themselves in no particular order. A supervisor reading
 * down the entry met the answers before the question they answered.
 *
 * Ordering by the template fixes that, and carrying the template's `label`
 * fixes a second, quieter loss: the reviewer was shown `Issues Concerns`
 * (a humanised key) where the coordinator had authored, say,
 * `Issues/Concerns Encountered`.
 *
 * Two fallbacks keep it honest rather than lossy:
 *  - a key the template does not mention is still rendered, after the known
 *    ones, with its humanised key — templates are edited over time and an older
 *    entry can hold a section that has since been renamed or removed, and
 *    silently dropping a student's writing is the one outcome worth avoiding;
 *  - with NO template on the payload at all (a legacy batch with
 *    `journal_template_id` null), this degrades to exactly the old behaviour.
 *
 * Blank fields are skipped rather than printing an empty label, which is what
 * the original did and is still right.
 */
export function journalContentFields(
  content: Record<string, string> | null | undefined,
  sections: ReviewTemplateSection[] | null | undefined,
): JournalContentField[] {
  const filled = Object.entries(content ?? {}).filter(
    ([, value]) => typeof value === 'string' && value.trim() !== '',
  )
  const byKey = new Map(filled)
  const ordered: JournalContentField[] = []

  for (const section of sections ?? []) {
    const value = byKey.get(section.key)
    if (value === undefined) continue

    ordered.push({
      key: section.key,
      label: section.label?.trim() || humanizeJournalKey(section.key),
      value,
      sipp: section.sipp,
    })
    byKey.delete(section.key)
  }

  for (const [key, value] of byKey) {
    ordered.push({ key, label: humanizeJournalKey(key), value, sipp: false })
  }

  return ordered
}
