import * as SecureStore from 'expo-secure-store';

// Determines which data set the dashboard/journals/calendar/etc. hooks show:
// - 'demo'  -> the fixed test account (student@interntrack.test) — seeded sample data
// - 'new'   -> a freshly registered student with no history yet — empty states
// - null    -> a real backend-authenticated session — hooks fetch real data,
//              falling back to the seeded demo data only if that fetch fails
//              (existing behavior, unchanged)
const ACCOUNT_KIND_KEY = 'interntrack_account_kind';

export type AccountKind = 'demo' | 'new' | null;

export async function getAccountKind(): Promise<AccountKind> {
  const kind = await SecureStore.getItemAsync(ACCOUNT_KIND_KEY);
  return kind === 'demo' || kind === 'new' ? kind : null;
}

export async function setAccountKind(kind: AccountKind): Promise<void> {
  if (kind) {
    await SecureStore.setItemAsync(ACCOUNT_KIND_KEY, kind);
  } else {
    await SecureStore.deleteItemAsync(ACCOUNT_KIND_KEY);
  }
}
