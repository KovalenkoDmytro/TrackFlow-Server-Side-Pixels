import type { PlatformDeliveryStat, PlatformDeliveryTotals } from '../types/api';

/**
 * Accepted / eligible, where eligible excludes other_account, expired and pending rows.
 * Mirrors App\Support\Analytics\DeliveryRate; used when the API omits delivery_rate.
 */
export function deliveryRate(row: Pick<PlatformDeliveryTotals, 'attempted' | 'delivered' | 'other_account' | 'expired' | 'pending'>): number | null {
  const eligible = Math.max(0, row.attempted - row.other_account - row.expired - row.pending);
  if (eligible === 0) return null;
  return Math.round(Math.min(100, Math.max(0, (row.delivered / eligible) * 100)) * 10) / 10;
}

export function rateOf(row: PlatformDeliveryStat | PlatformDeliveryTotals): number | null {
  return row.delivery_rate !== undefined ? row.delivery_rate : deliveryRate(row);
}

export function formatRate(value: number | null | undefined): string {
  if (value === null || value === undefined) return '—';
  return `${value % 1 === 0 ? value.toFixed(0) : value.toFixed(1)}%`;
}
