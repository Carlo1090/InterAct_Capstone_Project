// 'draft' = compiled locally but not yet submitted to a supervisor; 'pending'
// = submitted, awaiting review. Matches the real backend's actual student-
// facing vocabulary (an unsubmitted week is a real, distinct state — it was
// missing here before, which is what let a never-submitted week get
// mislabeled 'pending').
export type WeekStatus = 'draft' | 'pending' | 'approved' | 'returned';

export interface WeeklyLog {
  id: string;
  title: string;
  sub: string;
  status: WeekStatus;
  narrative?: string;
  comment?: string; // set when a supervisor returns the week for revision
}

export const mockWeeklyLogs: WeeklyLog[] = [
  {
    id: '8',
    title: 'Week 8 · May 19–25, 2025',
    sub: 'Compiled: May 26, 12:00 AM · 5 entries',
    status: 'pending',
    narrative:
      'MONDAY\nWorked on the UI component library, focusing on data tables and modal dialogs.\n\nTUESDAY\nFixed accessibility issues flagged in the form elements review.\n\nWEDNESDAY\nIntegrated the Google Fonts API for the typography system.\n\nTHURSDAY\nValidated responsive behavior of the sidebar layout across screen sizes.\n\nFRIDAY\nPaired with Engr. Beltran on component naming convention cleanup.',
  },
  {
    id: '7',
    title: 'Week 7 · May 12–18, 2025',
    sub: 'Compiled: May 19, 12:00 AM · 5 entries',
    status: 'approved',
    narrative: 'MONDAY\nReviewed sprint backlog and picked up the badge indicator component.\n\nTUESDAY\nBuilt and tested the badge indicator component.',
  },
  {
    id: '6',
    title: 'Week 6 · May 5–11, 2025',
    sub: 'Compiled: May 12, 12:00 AM · 5 entries',
    status: 'approved',
    narrative: 'MONDAY\nSet up the design token structure for the component library.',
  },
  {
    id: '5',
    title: 'Week 5 · Apr 28–May 4, 2025',
    sub: 'Compiled: May 5, 12:00 AM · 4 entries',
    status: 'returned',
    narrative: 'MONDAY\nStarted on the modal dialog component.\n\nTUESDAY\nContinued modal dialog work.',
    comment: 'Please add more detail on the specific accessibility issues you encountered and how they were resolved — this section is too brief for the weekly report.',
  },
  {
    id: '4',
    title: 'Week 4 · Apr 21–27, 2025',
    sub: 'Compiled: Apr 28, 12:00 AM · 5 entries',
    status: 'approved',
    narrative: 'MONDAY\nOnboarded onto the project and reviewed the codebase structure.',
  },
];
