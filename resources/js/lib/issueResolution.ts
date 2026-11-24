/**
 * Pure helpers for "Mark as resolved" on issue cards. No runtime imports, so they run
 * under `node --test` (tests/js).
 */
import type { DeliveryIssue } from '../types/api';

type Key = Pick<DeliveryIssue, 'platform' | 'code'>;

export function sameIssue(a: Key, b: Key): boolean {
  return a.platform === b.platform && a.code === b.code;
}

/**
 * Optimistic cache update: the same data with the issue removed. Works for the overview
 * response ({ issues }) and the platform analytics response ({ summary: { issues } });
 * anything else is returned untouched.
 */
export function withoutIssue<T>(data: T, key: Key): T {
  if (!data || typeof data !== 'object') return data;
  const record = data as Record<string, unknown>;

  if (Array.isArray(record.issues)) {
    return { ...record, issues: (record.issues as DeliveryIssue[]).filter((i) => !sameIssue(i, key)) } as T;
  }
  if (record.summary && typeof record.summary === 'object' && Array.isArray((record.summary as Record<string, unknown>).issues)) {
    const summary = record.summary as Record<string, unknown>;
    return { ...record, summary: { ...summary, issues: (summary.issues as DeliveryIssue[]).filter((i) => !sameIssue(i, key)) } } as T;
  }
  return data;
}

/** Text of the small confirm shown before resolving. */
export function confirmText(issue: Pick<DeliveryIssue, 'count'>): string {
  const n = issue.count.toLocaleString('en-US');
  return `Hide ${n} failure${issue.count === 1 ? '' : 's'} from issues and the health score? If this error happens again, the card comes back with only the new failures.`;
}

/** Note shown on a card that came back after being resolved, e.g. "Came back after you marked it resolved on Nov 24." */
export function recurrenceNote(issue: Pick<DeliveryIssue, 'resolved_at'>, timeZone?: string): string | null {
  if (!issue.resolved_at) return null;
  const date = new Date(issue.resolved_at);
  if (Number.isNaN(date.getTime())) return null;
  const day = date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', ...(timeZone ? { timeZone } : {}) });
  return `Came back after you marked it resolved on ${day}. Showing only new failures.`;
}
