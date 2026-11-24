// Run with: npm run test:js  (Node 22.6+ strips the TypeScript types natively)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { eventChips, impactText } from '../../resources/js/lib/issueImpact.ts';
import { guideFor } from '../../resources/js/lib/errorCatalog.ts';

const ev = (event: string, label: string, count: number) => ({ event, label, count });

test('mostly product views: wording comes from the affected events', () => {
  const issue = { count: 410, events: [ev('view_item', 'View Item', 408), ev('view_cart', 'View Cart', 1), ev('add_to_cart', 'Add to Cart', 1)] };
  assert.equal(impactText(issue, 'Google Ads'), 'Mostly product views are affected (408 of 410). 1 cart or checkout event is also affected. Purchases are not affected.');
});

test('only browse events mention bidding is unaffected', () => {
  const issue = { count: 9, events: [ev('view_item', 'View Item', 9)] };
  assert.equal(impactText(issue, 'Google Ads'), 'Only product views are affected (9). Purchases are not affected. Bidding is unaffected unless you optimise for these events.');
});

test('a purchase failure names the platform of the issue', () => {
  const issue = { count: 51, events: [ev('view_item', 'View Item', 43), ev('view_cart', 'View Cart', 4), ev('add_to_cart', 'Add to Cart', 3), ev('purchase', 'Purchase', 1)] };
  const text = impactText(issue, 'Meta');
  assert.match(text, /^1 purchase not received by Meta, so reported conversions and bidding are affected\./);
  assert.match(text, /Most other failures are product views \(43 of 51\)\./);
  assert.doesNotMatch(text, /Google/);
});

test('mixed events are listed instead of "mostly"', () => {
  const issue = { count: 10, events: [ev('add_to_cart', 'Add to Cart', 5), ev('view_item', 'View Item', 5)] };
  assert.equal(impactText(issue, 'Meta'), 'Affected: add-to-cart events (5) and product views (5). Purchases are not affected.');
});

test('chips always include Purchase and account for hidden events', () => {
  const events = [ev('view_item', 'View Item', 43), ev('view_cart', 'View Cart', 4), ev('add_to_cart', 'Add to Cart', 3), ev('purchase', 'Purchase', 1)];
  const chips = eventChips(events);
  assert.deepEqual(chips.shown.map((e) => e.event), ['view_item', 'view_cart', 'purchase']);
  assert.equal(chips.hiddenEvents, 1);
  assert.equal(chips.shown.reduce((s, e) => s + e.count, 0) + chips.hiddenCount, 51);
});

test('Meta guide is used on Meta cards with Meta CTA and docs', () => {
  const guide = guideFor('META_ACCESS_TOKEN', 'meta', 'Meta');
  assert.equal(guide.platform, 'meta');
  assert.equal(guide.cta, 'settings');
  assert.match(guide.ctaLabel ?? '', /Meta/);
  assert.match(guide.docsUrl ?? '', /facebook\.com/);
  assert.match(guide.docsLabel ?? '', /Meta/);
});

test('a platform-specific guide never appears on another platform', () => {
  const metaOnGoogle = guideFor('META_ACCESS_TOKEN', 'google_ads', 'Google Ads');
  assert.doesNotMatch(`${metaOnGoogle.title} ${metaOnGoogle.explanation} ${metaOnGoogle.steps.join(' ')}`, /Meta/);
  assert.match(metaOnGoogle.title, /Google Ads/);
  assert.doesNotMatch(metaOnGoogle.docsUrl ?? '', /facebook/);

  const googleOnMeta = guideFor('CONVERSION_PRECEDES_EVENT', 'meta', 'Meta');
  assert.doesNotMatch(`${googleOnMeta.title} ${googleOnMeta.explanation}`, /Google/);
  assert.match(googleOnMeta.docsUrl ?? '', /facebook\.com/);
});

test('Google Ads token failures get a Google Ads guide', () => {
  const guide = guideFor('GOOGLE_ADS_AUTH', 'google_ads', 'Google Ads');
  assert.equal(guide.platform, 'google_ads');
  assert.match(guide.title, /Google Ads/);
  assert.match(guide.ctaLabel ?? '', /Google Ads settings/);
});
