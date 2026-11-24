/**
 * Impact wording and event chips for delivery issue cards, derived from the events actually
 * affected. Kept free of runtime imports so it runs under `node --test` (tests/js).
 */
import type { DeliveryIssue } from '../types/api';

type IssueEvent = DeliveryIssue['events'][number];

const CHECKOUT = new Set(['begin_checkout', 'add_payment_info', 'add_shipping_info']);
const CART = new Set(['add_to_cart']);

/** Plural noun used in sentences ("Mostly product views are affected"). */
const NOUNS: Record<string, [singular: string, plural: string]> = {
  purchase: ['purchase', 'purchases'],
  view_item: ['product view', 'product views'],
  view_cart: ['cart view', 'cart views'],
  search: ['search', 'searches'],
  add_to_cart: ['add-to-cart event', 'add-to-cart events'],
  remove_from_cart: ['remove-from-cart event', 'remove-from-cart events'],
  begin_checkout: ['checkout start', 'checkout starts'],
  add_payment_info: ['payment info step', 'payment info steps'],
  add_shipping_info: ['shipping info step', 'shipping info steps'],
};

/** Share of failures one event must reach to be called "mostly". */
export const DOMINANT_SHARE = 0.8;

function noun(e: IssueEvent, count: number): string {
  const pair = NOUNS[e.event];
  if (pair) return count === 1 ? pair[0] : pair[1];
  const label = e.label.toLowerCase();
  return count === 1 ? `${label} event` : `${label} events`;
}

function plural(count: number, one: string, many: string): string {
  return `${count.toLocaleString('en-US')} ${count === 1 ? one : many}`;
}

/** Sentence(s) describing who is affected, e.g. "Mostly product views are affected (408 of 410). Purchases are not affected." */
export function impactText(issue: Pick<DeliveryIssue, 'count' | 'events'>, platformLabel: string): string {
  const events = [...issue.events].sort((a, b) => b.count - a.count);
  const total = Math.max(issue.count, events.reduce((sum, e) => sum + e.count, 0));
  if (events.length === 0 || total === 0) return `Some events were not received by ${platformLabel}.`;

  const purchases = events.find((e) => e.event === 'purchase')?.count ?? 0;
  const others = events.filter((e) => e.event !== 'purchase');
  const othersTotal = others.reduce((sum, e) => sum + e.count, 0);
  const highValue = others.filter((e) => CHECKOUT.has(e.event) || CART.has(e.event)).reduce((sum, e) => sum + e.count, 0);
  const parts: string[] = [];

  if (purchases > 0) {
    parts.push(`${plural(purchases, 'purchase', 'purchases')} not received by ${platformLabel}, so reported conversions and bidding are affected.`);
    if (othersTotal > 0) {
      const top = others[0];
      parts.push(top.count / othersTotal >= DOMINANT_SHARE && top.count > 1
        ? `Most other failures are ${noun(top, 2)} (${top.count.toLocaleString('en-US')} of ${total.toLocaleString('en-US')}).`
        : `Other affected events: ${describeList(others)}.`);
    }
    return parts.join(' ');
  }

  const top = events[0];
  if (events.length === 1) {
    parts.push(top.count === 1 ? `Only 1 ${noun(top, 1)} is affected.` : `Only ${noun(top, 2)} are affected (${top.count.toLocaleString('en-US')}).`);
  } else if (top.count / total >= DOMINANT_SHARE) {
    parts.push(`Mostly ${noun(top, 2)} are affected (${top.count.toLocaleString('en-US')} of ${total.toLocaleString('en-US')}).`);
  } else {
    parts.push(`Affected: ${describeList(events)}.`);
  }

  const topIsHighValue = CHECKOUT.has(top.event) || CART.has(top.event);
  const otherHighValue = highValue - (topIsHighValue ? top.count : 0);
  if (events.length > 1 && !topIsHighValue && otherHighValue > 0 && top.count / total >= DOMINANT_SHARE) {
    parts.push(`${plural(otherHighValue, 'cart or checkout event is', 'cart or checkout events are')} also affected.`);
  }

  parts.push('Purchases are not affected.');
  if (highValue === 0) parts.push('Bidding is unaffected unless you optimise for these events.');

  return parts.join(' ');
}

function describeList(events: IssueEvent[]): string {
  const shown = events.slice(0, 3).map((e) => `${noun(e, e.count)} (${e.count.toLocaleString('en-US')})`);
  const rest = events.slice(3).reduce((sum, e) => sum + e.count, 0);
  if (rest > 0) shown.push(`${rest.toLocaleString('en-US')} other`);
  return shown.length <= 1 ? shown.join('') : `${shown.slice(0, -1).join(', ')} and ${shown[shown.length - 1]}`;
}

/**
 * Event chips for a card: the biggest events, always including Purchase when present,
 * plus how many failures the hidden chips hold, so the chips always add up to the total.
 */
export function eventChips(events: IssueEvent[], max = 3): { shown: IssueEvent[]; hiddenEvents: number; hiddenCount: number } {
  const sorted = [...events].sort((a, b) => b.count - a.count);
  let shown = sorted.slice(0, max);
  const purchase = sorted.find((e) => e.event === 'purchase');
  if (purchase && !shown.includes(purchase)) shown = [...shown.slice(0, max - 1), purchase];
  const hidden = sorted.filter((e) => !shown.includes(e));
  return { shown, hiddenEvents: hidden.length, hiddenCount: hidden.reduce((sum, e) => sum + e.count, 0) };
}
