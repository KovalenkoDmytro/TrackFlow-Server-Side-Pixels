// resources/js/api/overview.ts
import type { ApiClient } from './client';
import type { OverviewResponse } from '../types/api';

export async function getOverview(client: ApiClient, days: number): Promise<OverviewResponse> {
  const res = await client(`/api/overview?days=${days}`);
  if (!res.ok) {
    const body = await res.json().catch(() => ({})) as { message?: string };
    throw new Error(body.message ?? 'Failed to load overview');
  }
  return res.json() as Promise<OverviewResponse>;
}
