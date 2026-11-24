// Run with: npm run test:js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { reportedText, supportConfirmText, supportMode, withReported } from '../../resources/js/lib/issueSupport.ts';

const issue = (platform: string, code: string, support?: { primary: boolean; reported_at: string | null }, count = 1) =>
  ({ platform, code, count, impact: count, events: [], message: '', last_seen_at: null, support });

test('code-side errors get "Send to support" as the primary action', () => {
  assert.equal(supportMode(issue('google_ads', 'CONVERSION_PRECEDES_EVENT', { primary: true, reported_at: null })), 'primary');
});

test('errors the merchant can fix keep their action, with a secondary "Contact support"', () => {
  assert.equal(supportMode(issue('meta', 'META_ACCESS_TOKEN', { primary: false, reported_at: null })), 'secondary');
  assert.equal(supportMode(issue('meta', 'META_ACCESS_TOKEN')), 'secondary', 'older payloads without support info');
});

test('a reported issue shows the calm state, whatever its classification', () => {
  assert.equal(supportMode(issue('google_ads', 'UNKNOWN', { primary: true, reported_at: '2026-11-24T18:00:00+00:00' })), 'reported');
  assert.equal(supportMode(issue('meta', 'META_ACCESS_TOKEN', { primary: false, reported_at: '2026-11-24T18:00:00+00:00' })), 'reported');
});

test('reported text names the date and tells the merchant nothing is needed', () => {
  const reported = issue('google_ads', 'UNKNOWN', { primary: true, reported_at: '2026-11-24T18:00:00+00:00' });
  assert.equal(reportedText(reported, 'UTC'), "Sent to support on Nov 24. We're working on it. You don't need to do anything.");
  assert.equal(reportedText(issue('google_ads', 'UNKNOWN', { primary: true, reported_at: null })), null);
  assert.equal(reportedText(issue('google_ads', 'UNKNOWN', { primary: true, reported_at: 'nope' })), null);
});

test('confirm text uses the platform and failure count', () => {
  assert.match(supportConfirmText({ count: 410 }, 'Google Ads'), /Google Ads error .* 410 failed events/);
  assert.match(supportConfirmText({ count: 1 }, 'Meta'), /1 failed event,/);
});

test('optimistic update marks only the reported issue, on overview and platform responses', () => {
  const at = '2026-11-24T18:00:00.000Z';
  const overview = { issues: [issue('google_ads', 'UNKNOWN', { primary: true, reported_at: null }), issue('meta', 'UNKNOWN', { primary: true, reported_at: null })] };
  const next = withReported(overview, { platform: 'google_ads', code: 'UNKNOWN' }, at);
  assert.equal(next.issues[0].support?.reported_at, at);
  assert.equal(next.issues[1].support?.reported_at, null);
  assert.equal(overview.issues[0].support?.reported_at, null, 'does not mutate the cache');

  const analytics = { summary: { issues: [issue('google_ads', 'TOO_RECENT_EVENT')] } };
  assert.equal(withReported(analytics, { platform: 'google_ads', code: 'TOO_RECENT_EVENT' }, at).summary.issues[0].support?.reported_at, at);
  assert.equal(withReported(null, { platform: 'meta', code: 'X' }, at), null);
});
