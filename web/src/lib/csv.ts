/**
 * Client-side CSV downloads. Shared by the bulk import's credentials backup and
 * its rows-to-fix file, so both escape cells the same way.
 *
 * Nothing here is persisted: the file is built in memory from data already on
 * screen and handed to the browser, which is what lets the credentials backup
 * stay a one-time copy (see lib/formDraft.ts on never storing a credential).
 */

/**
 * One CSV cell. Excel and Sheets run a cell that begins with = + - @ (or a tab
 * or carriage return) as a formula, and every value written here came from an
 * uploaded file, so such a cell is prefixed with an apostrophe to keep it
 * plain text.
 */
export const csvCell = (value: unknown): string => {
  const text = value === null || value === undefined ? '' : String(value)
  const safe = /^[=+\-@\t\r]/.test(text) ? `'${text}` : text
  return `"${safe.replace(/"/g, '""')}"`
}

export const toCsv = (header: string[], rows: unknown[][]): string =>
  [header, ...rows].map((row) => row.map(csvCell).join(',')).join('\r\n')

/** Hand a CSV string to the browser as a file download. */
export const downloadCsv = (filename: string, content: string): void => {
  // The BOM makes Excel open the file as UTF-8, so a name like "Niño" survives.
  const blob = new Blob(['﻿', content], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.click()
  URL.revokeObjectURL(url)
}
