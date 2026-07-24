/**
 * Turns a raw Laravel validation-error field key (snake_case, dotted paths,
 * numeric array segments) into a human-readable label for display — e.g.
 * "end_date" -> "End Date", "sections.0.label" -> "Section 1 Label".
 * Generic on purpose: new fields never need a hardcoded mapping entry.
 */
export function friendlyFieldLabel(field: string): string {
  const humanize = (segment: string): string =>
    segment.replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())

  const words: string[] = []

  for (const segment of field.split('.')) {
    if (/^\d+$/.test(segment)) {
      // A numeric path segment names the previous word's position (1-based)
      // — singularize it ("Sections" -> "Section") so it reads as "Section 1".
      const previous = words.pop() ?? 'Item'
      const singular = previous.endsWith('s') ? previous.slice(0, -1) : previous
      words.push(`${singular} ${Number(segment) + 1}`)
      continue
    }

    words.push(humanize(segment))
  }

  return words.join(' ')
}
