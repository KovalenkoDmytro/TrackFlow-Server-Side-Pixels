// Run with: npm run test:js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { confirmText, recurrenceNote, sameIssue, withoutIssue } from '../../resources/js/lib/issueResolution.ts';

const issue = (platform: string, code: string, count = 1) => ({ platform, code, count, impact: count, events: [], message: '', last_seen_at: null });

test('optimistic update removes only the resolved issue from the overview', () => {
  const overview = { health: { score: 79 }, issues: [issue('google_ads', 'CONVERSION_PRECEDES_EVENT', 410), issue('google_ads', 'GOOGLE_ADS_AUTH', 51), issue('meta', 'CONVERSION_PRECEDES_EVENT', 2)] };
  const next = withoutIssue(overview, { platform: 'google_ads', code: 'CONVERSION_PRECEDES_EVENT' });

  assert.deepEqual(next.issues.map((i) => `${i.platform}|${i.code}`), ['google_ads|GOOGLE_ADS_AUTH', 'meta|CONVERSION_PRECEDES_EVENT']);
  assert.equal(next.health.score, 79);
  assert.equal(overview.issues.length, 3, 'does not mutate the cached object');
});

test('optimistic update also works on the platform analytics response', () => {
  const analytics = { meta: { retention_days: 90 }, summary: { totals: { failed: 3 }, issues: [issue('google_ads', 'TOO_RECENT_EVENT')] } };
  const next = withoutIssue(analytics, { platform: 'google_ads', code: 'TOO_RECENT_EVENT' });

  assert.deepEqual(next.summary.issues, []);
  assert.equal(next.summary.totals.failed, 3, 'raw totals stay');
});

test('optimistic update leaves unrelated data untouched', () => {
  const counts = { summary: { counts: [], total: 0 } };
  assert.equal(withoutIssue(counts, { platform: 'meta', code: 'X' }), counts);
  assert.equal(withoutIssue(undefined, { platform: 'meta', code: 'X' }), undefined);
});

test('issues match on platform and code', () => {
  assert.equal(sameIssue(issue('meta', 'X'), { platform: 'meta', code: 'X' }), true);
  assert.equal(sameIssue(issue('meta', 'X'), { platform: 'google_ads', code: 'X' }), false);
});

test('confirm text states what is hidden and that it can come back', () => {
  assert.equal(confirmText({ count: 821 }), 'Hide 821 failures from issues and the health score? If this error happens again, the card comes back with only the new failures.');
  assert.match(confirmText({ count: 1 }), /^Hide 1 failure from/);
});

test('a card that came back explains it', () => {
  assert.equal(recurrenceNote({ resolved_at: '2026-11-24T18:00:00+00:00' }, 'UTC'), 'Came back after you marked it resolved on Nov 24. Showing only new failures.');
  assert.equal(recurrenceNote({ resolved_at: null }), null);
  assert.equal(recurrenceNote({}), null);
});
