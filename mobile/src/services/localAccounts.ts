import * as SecureStore from 'expo-secure-store';

// Offline-only fallback for Sign Up, mirroring the mock-fallback pattern used
// everywhere else in this app (see src/services/api.ts). When the real
// POST /api/register call succeeds, none of this is used — the backend is
// the source of truth. When it can't be reached yet, a new account is kept
// here so the student can log back in and see their own (empty) dashboard
// on this device.
//
// IMPORTANT: this stores the password in plain text on-device. That is only
// acceptable because it exists purely to unblock local development before a
// backend exists — never do this in a shipped app. Delete this file once
// the real registration endpoint is live and confirmed working.
const LOCAL_ACCOUNTS_KEY = 'interntrack_local_accounts';

export interface LocalAccount {
  name: string;
  studentId: string;
  email: string;
  password: string;
}

async function readAll(): Promise<LocalAccount[]> {
  try {
    const raw = await SecureStore.getItemAsync(LOCAL_ACCOUNTS_KEY);
    return raw ? (JSON.parse(raw) as LocalAccount[]) : [];
  } catch {
    return [];
  }
}

async function writeAll(accounts: LocalAccount[]): Promise<void> {
  await SecureStore.setItemAsync(LOCAL_ACCOUNTS_KEY, JSON.stringify(accounts));
}

export async function findLocalAccount(email: string, password: string): Promise<LocalAccount | null> {
  const accounts = await readAll();
  const normalized = email.trim().toLowerCase();
  return accounts.find((a) => a.email === normalized && a.password === password) ?? null;
}

export async function emailIsTaken(email: string): Promise<boolean> {
  const accounts = await readAll();
  return accounts.some((a) => a.email === email.trim().toLowerCase());
}

export async function createLocalAccount(account: LocalAccount): Promise<void> {
  const accounts = await readAll();
  accounts.push({ ...account, email: account.email.trim().toLowerCase() });
  await writeAll(accounts);
}

// Separate from the account list: just the current session's profile fields
// (no password), so Profile/Info Sheet screens can display them without
// needing to re-check credentials.
const CURRENT_PROFILE_KEY = 'interntrack_current_local_profile';

export interface LocalProfile {
  name: string;
  studentId: string;
  email: string;
}

export async function setCurrentLocalProfile(profile: LocalProfile): Promise<void> {
  await SecureStore.setItemAsync(CURRENT_PROFILE_KEY, JSON.stringify(profile));
}

export async function getCurrentLocalProfile(): Promise<LocalProfile | null> {
  try {
    const raw = await SecureStore.getItemAsync(CURRENT_PROFILE_KEY);
    return raw ? (JSON.parse(raw) as LocalProfile) : null;
  } catch {
    return null;
  }
}

export async function clearCurrentLocalProfile(): Promise<void> {
  await SecureStore.deleteItemAsync(CURRENT_PROFILE_KEY);
}
