// resources/js/api/deliveryIssues.ts
import type { ApiClient } from './client';

export interface IssueKey {
  platform: string;
  code: string;
}

async function fail(res: Response, fallback: string): Promise<never> {
  const body = await res.json().catch(() => ({})) as { message?: string };
  throw new Error(body.message ?? fallback);
}

/** Mark a delivery issue resolved: failures up to now stop counting for this shop. */
export async function resolveIssue(client: ApiClient, key: IssueKey): Promise<{ resolved_at: string }> {
  const res = await client('/api/delivery-issues/resolutions', { method: 'POST', body: JSON.stringify(key) });
  if (!res.ok) return fail(res, 'Could not mark the issue as resolved');
  return res.json() as Promise<{ resolved_at: string }>;
}

/** Undo a resolution: earlier failures count again. */
export async function unresolveIssue(client: ApiClient, key: IssueKey): Promise<void> {
  const params = new URLSearchParams({ platform: key.platform, code: key.code });
  const res = await client(`/api/delivery-issues/resolutions?${params.toString()}`, { method: 'DELETE' });
  if (!res.ok) await fail(res, 'Could not undo');
}
