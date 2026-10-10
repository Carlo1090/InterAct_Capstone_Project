import type { GroupSheetInternPin, GroupSheetLocation, GroupSheetLocationChoice } from '@/types/api'

/**
 * The group sheet's sketch box, client side: which point it prints, and how far
 * each intern's pin is from it.
 *
 * The server decides the majority (App\Support\PinConsensus); this only
 * re-measures distances after the coordinator picks a different point, so the
 * list reacts before the sheet is saved. The radius MUST match
 * PinConsensus::RADIUS_METRES.
 */
export const AGREEMENT_RADIUS_METRES = 150

/** The printed box, 518.4 x 189.65pt (BuildsGroupInfoSheetPdf::GROUP_SKETCH_*). */
export const GROUP_SKETCH_RATIO = 518.4 / 189.65

/** The point the sheet will print: the coordinator's choice, else the majority. */
export type EffectiveLocation = {
  lat: number
  lng: number
  zoom: number | null
  label: string | null
  source: GroupSheetLocation['source']
  enrollment_id: number | null
}

export const effectiveLocation = (
  choice: GroupSheetLocationChoice | null,
  majority: GroupSheetLocation | null,
): EffectiveLocation | null => (choice ? { ...choice } : majority)

/** Haversine, the same formula as App\Support\GeoDistance. */
export const metresBetween = (lat1: number, lng1: number, lat2: number, lng2: number): number => {
  const rad = Math.PI / 180
  const a =
    Math.sin(((lat2 - lat1) * rad) / 2) ** 2 +
    Math.cos(lat1 * rad) * Math.cos(lat2 * rad) * Math.sin(((lng2 - lng1) * rad) / 2) ** 2

  return 2 * 6371008.8 * Math.asin(Math.min(1, Math.sqrt(a)))
}

export const distanceFrom = (location: EffectiveLocation, pin: GroupSheetInternPin): number =>
  metresBetween(location.lat, location.lng, pin.lat, pin.lng)

export const formatDistance = (metres: number): string =>
  metres < 1000 ? `${Math.round(metres)} m` : `${(metres / 1000).toFixed(1)} km`

/** The coordinator's preview, drawn server-side at the printed box's shape. */
export const previewUrl = (location: EffectiveLocation | null): string => {
  if (!location) return ''

  const query = new URLSearchParams({
    lat: String(location.lat),
    lng: String(location.lng),
    zoom: String(location.zoom ?? 17),
  })

  return `/api/coordinator/location-preview?${query.toString()}`
}
