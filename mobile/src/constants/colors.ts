// The one place a colour is defined. Never inline a hex code in a
// screen/component — import from here instead.
//
// NAVY, GENTLED (2026-09-09, project owner). The family is unchanged; what
// changed is its INTENSITY. The old ground was #0a1628, a near-black sitting at
// 18.13:1 against white — a contrast wall — and the old action colour #1e4d9b
// carried 0.83 saturation, which is close to electric and vibrates against a
// white page. Over a long shift that combination is what makes a screen tiring.
//
// Every value below was measured, not picked by eye:
//   ground   #20304C on white ... 13.22:1  (was 18.13:1 — less of a wall)
//   primary  #3A5A8F on white .... 6.91:1  (floor is 4.5:1; saturation 0.42)
//   accent   #A3C0E6 on ground ... 7.08:1  (light-on-dark, splash + stat cards)
//   heading  #16213A on canvas .. 14.89:1
//   body     #47566E on canvas ... 6.92:1
//
// STATUS COLOURS ARE DELIBERATELY UNCHANGED. Green/red/amber carry meaning
// (submitted, missing, needs action) rather than brand, and re-tinting them
// toward navy would weaken exactly the signals that must stay loud.
export const colors = {
  // The dark ground: top bar, splash, profile header, accent stat cards.
  blue900: '#20304c',
  // A step LIGHTER than the ground on purpose — it draws borders and progress
  // fills ON the ground, so it has to be visible against it.
  blue800: '#2a3f60',
  // Secondary: outlined buttons, links.
  blue700: '#345182',
  // PRIMARY — filled buttons, the active tab. The most-used token in the app.
  blue600: '#3a5a8f',
  // A lighter primary for small markers (today on the calendar, unread dots).
  blue500: '#4a6ea8',
  blue400: '#7c9dcb',
  // Accent — light-on-dark ONLY. Illegible on white (1.9:1); never put it there.
  blue300: '#a3c0e6',
  blue200: '#c9d6ea',
  blue100: '#dfe6f0',
  blue50: '#eaeff7',
  white: '#ffffff',
  // Canvas: a faint blue cast rather than full-brightness white.
  gray50: '#f4f7fb',
  gray100: '#eaeff7',
  gray200: '#dfe6f0',
  gray300: '#c9d2e0',
  // Was #94a3b8, which sat at 2.39:1 on the page ground — under even the 3:1
  // floor for large text. #7c8aa3 lifts it to 3.25:1. Still a muted label
  // colour by intent, so keep it off anything that must be read closely.
  gray400: '#7c8aa3',
  gray500: '#64748b',
  gray600: '#47566e',
  gray800: '#223049',
  black: '#16213a',
  green: '#22c55e',
  orange: '#f97316',
  red: '#ef4444',
  redDark: '#dc2626',
  amberBg: '#fef9c3',
  amberTx: '#92400e',
  greenBg: '#dcfce7',
  greenTx: '#166534',
  redBg: '#fee2e2',
  redTx: '#991b1b',
  warnBg: '#fefce8',
  warnBorder: '#fde047',
  warnTx: '#713f12',
  // The offline treatment gets its OWN tokens rather than borrowing the warn
  // trio — see Banner's `offline` variant for why it had to stop being grey.
  offlineBg: '#fff4e0',
  offlineStripe: '#c2620d',
  offlineBorder: '#f2c98a',
  offlineTx: '#7a3e06',
} as const;

export type ColorKey = keyof typeof colors;
