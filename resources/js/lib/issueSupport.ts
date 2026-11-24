/**
 * Pure helpers for "Send to support" on issue cards. No runtime imports, so they run
 * under `node --test` (tests/js). Which codes need support is decided on the server
 * (DeliveryErrorCode::needsSupport) and arrives as issue.support.primary.
 */
import type { DeliveryIssue } from '../types/api';

type Key = Pick<DeliveryIssue, 'platform' | 'code'>;

/**
 * - reported: an open support report exists; the card shows a calm state.
 * - primary: the merchant cannot fix this; "Send to support" is the main button.
 * - secondary: the merchant has a clear fix; a small "Contact support" link is shown.
 */
export type SupportMode = 'reported' | 'primary' | 'secondary';

export function supportMode(issue: Pick<DeliveryIssue, 'support'>): SupportMode {
  if (issue.support?.reported_at) return 'reported';
  return issue.support?.primary ? 'primary' : 'secondary';
}

/** Calm message on a reported card, e.g. "Sent to support on Nov 24. We're working on it. You don't need to do anything." */
export function reportedText(issue: Pick<DeliveryIssue, 'support'>, timeZone?: string): string | null {
  const at = issue.support?.reported_at;
  if (!at) return null;
  const date = new Date(at);
  if (Number.isNaN(date.getTime())) return null;
  const day = date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', ...(timeZone ? { timeZone } : {}) });
  return `Sent to support on ${day}. We're working on it. You don't need to do anything.`;
}

/** Text of the small confirm shown before sending. */
export function supportConfirmText(issue: Pick<DeliveryIssue, 'count'>, platformLabel: string): string {
  const n = issue.count.toLocaleString('en-US');
  return `TrackFlow support will get this ${platformLabel} error with its technical details and a few of the ${n} failed event${issue.count === 1 ? '' : 's'}, and will follow up by email.`;
}

function mark(issues: DeliveryIssue[], key: Key, reportedAt: string): DeliveryIssue[] {
  return issues.map((i) => (i.platform === key.platform && i.code === key.code
    ? { ...i, support: { primary: i.support?.primary ?? true, reported_at: reportedAt } }
    : i));
}

/**
 * Optimistic cache update: the same data with the issue marked as reported. Works for the
 * overview response ({ issues }) and the platform analytics response ({ summary: { issues } }).
 */
export function withReported<T>(data: T, key: Key, reportedAt: string): T {
  if (!data || typeof data !== 'object') return data;
  const record = data as Record<string, unknown>;

  if (Array.isArray(record.issues)) {
    return { ...record, issues: mark(record.issues as DeliveryIssue[], key, reportedAt) } as T;
  }
  if (record.summary && typeof record.summary === 'object' && Array.isArray((record.summary as Record<string, unknown>).issues)) {
    const summary = record.summary as Record<string, unknown>;
    return { ...record, summary: { ...summary, issues: mark(summary.issues as DeliveryIssue[], key, reportedAt) } } as T;
  }
  return data;
}
