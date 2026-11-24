// resources/js/features/analytics/PlatformAnalyticsPage.tsx
import { useNavigate, useParams } from 'react-router-dom';
import { Alert, Box, Button, Card, Typography } from '@mui/material';
import { PageLayout } from '../../components/ui/PageLayout';
import { MetricTile } from '../../components/ui/MetricTile';
import { IssueCard } from '../../components/ui/IssueCard';
import { HowItWorks } from '../../components/ui/HowItWorks';
import { StatusBadge } from '../../components/ui/StatusBadge';
import { EmptyState } from '../../components/ui/EmptyState';
import { EventCountsTable } from './components/EventCountsTable';
import { GoogleAdsAttributionTable } from './components/GoogleAdsAttributionTable';
import { PlatformDeliveryTable } from './components/PlatformDeliveryTable';
import { DEFAULT_RETENTION_DAYS, PeriodControls, usePeriodState } from './components/PeriodControls';
import { useAnalytics } from './hooks/useAnalytics';
import { useShopStatus } from '../home/hooks/useShopStatus';
import { formatRate, rateOf } from '../../lib/deliveryRate';
import { platformInfo } from '../../lib/platforms';
import type { AnalyticsResponse, PlatformDeliveryResponse, PlatformDeliveryTotals } from '../../types/api';

const EMPTY_TOTALS: PlatformDeliveryTotals = { attempted: 0, delivered: 0, failed: 0, pending: 0, other_account: 0, expired: 0 };

const HOW_IT_WORKS: Record<string, string[]> = {
  google_ads: [
    'TrackFlow matches each storefront event to its Google Ads click (gclid) and uploads it server-side to the conversion action mapped for that event.',
    'Accepted means Google Ads took the conversion. Skipped uploads (click from another Google Ads account, or click older than the conversion window) are not failures and are left out of the delivery rate.',
    'Delivery rate = accepted ÷ eligible uploads. Eligible excludes skipped and still-pending uploads.',
  ],
  meta: [
    'TrackFlow sends storefront events to the Meta Conversions API from the server, using the browser and click IDs (fbp, fbc) captured by the web pixel.',
    'Delivered means Meta confirmed it received the event. Delivery rate = delivered ÷ eligible events (pending events are left out until Meta answers).',
  ],
  ga4: [
    'TrackFlow sends storefront events to Google Analytics 4 with the Measurement Protocol, using the GA client ID captured by the web pixel.',
    'GA4 does not report per-event delivery results, so this page shows events that carry a GA4 client ID. Expect GA4 reports to differ slightly because of GA4 processing and consent.',
  ],
};

export function PlatformAnalyticsPage() {
  const { platform = '' } = useParams<{ platform: string }>();
  const navigate = useNavigate();
  const info = platformInfo(platform);
  const period = usePeriodState(platform);
  const { data, isFetching, error } = useAnalytics(period.params);
  const shop = useShopStatus();

  if (!info || info.comingSoon) {
    return (
      <PageLayout title={info?.label ?? 'Platform'} backTo="/">
        <EmptyState title={`${info?.label ?? 'This platform'} is coming soon`} description="This integration is being prepared. You can keep managing your connected platforms from the overview." actionLabel="Back to overview" onAction={() => navigate('/')} />
      </PageLayout>
    );
  }

  const isDelivery = platform === 'google_ads' || platform === 'meta';
  const delivery = isDelivery ? (data as PlatformDeliveryResponse | undefined)?.summary : undefined;
  const counts = !isDelivery || platform === 'google_ads' ? (data as AnalyticsResponse | undefined)?.summary : undefined;
  const totals = delivery?.totals ?? EMPTY_TOTALS;
  const issues = delivery?.issues ?? [];
  const retentionDays = data?.meta?.retention_days ?? DEFAULT_RETENTION_DAYS;
  const connected = isDelivery ? delivery?.integration?.active ?? Boolean(shop.data?.integrations?.[platform]) : Boolean(shop.data?.integrations?.[platform]);
  const rate = rateOf(totals);
  const skipped = totals.other_account + totals.expired;

  const metrics = isDelivery
    ? [
      { label: platform === 'google_ads' ? 'Upload attempts' : 'Events sent', value: totals.attempted.toLocaleString() },
      { label: platform === 'google_ads' ? 'Accepted' : 'Delivered', value: totals.delivered.toLocaleString(), caption: rate === null ? 'No eligible events yet' : `${formatRate(rate)} delivery rate` },
      platform === 'google_ads'
        ? { label: 'Skipped', value: skipped.toLocaleString(), caption: `Other account ${totals.other_account.toLocaleString()} · Click too old ${totals.expired.toLocaleString()}` }
        : { label: 'Pending', value: totals.pending.toLocaleString(), caption: 'Waiting for Meta to answer' },
      { label: 'Failed', value: totals.failed.toLocaleString(), caption: issues.length > 0 ? `${issues.length} issue${issues.length === 1 ? '' : 's'} below` : 'No failures', critical: totals.failed > 0 },
    ]
    : [
      { label: 'Events with GA4 client ID', value: (counts?.total ?? 0).toLocaleString() },
      { label: 'Purchases', value: (counts?.counts.find((c) => c.event === 'purchase')?.count ?? 0).toLocaleString() },
      { label: 'Add to cart', value: (counts?.counts.find((c) => c.event === 'add_to_cart')?.count ?? 0).toLocaleString() },
      { label: 'Top event', value: [...(counts?.counts ?? [])].sort((a, b) => b.count - a.count)[0]?.label ?? '—' },
    ];

  return (
    <PageLayout
      title={info.label}
      subtitle={info.api}
      titleMeta={connected ? <StatusBadge tone="success" dot>Connected</StatusBadge> : <StatusBadge>Not connected</StatusBadge>}
      backTo="/"
      actions={<><Button variant="text" size="small" onClick={() => navigate('/analytics')}>All events</Button><Button variant="outlined" onClick={() => navigate(info.settingsRoute)}>Settings</Button></>}
    >
      {error && <Alert severity="error" sx={{ mb: 2 }}>{error.message}</Alert>}
      <PeriodControls state={period} retentionDays={retentionDays} period={data?.summary?.period ?? null} loading={isFetching} />

      <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr 1fr', lg: 'repeat(4, 1fr)' }, gap: 2, mb: 2 }}>
        {metrics.map((m) => <MetricTile key={m.label} label={m.label} value={isFetching ? '…' : m.value} caption={'caption' in m ? m.caption : undefined} critical={'critical' in m ? m.critical : false} />)}
      </Box>

      {!isFetching && issues.length > 0 && (
        <Box sx={{ display: 'grid', gap: 2, mb: 2 }}>
          {issues.map((issue) => <IssueCard key={`${issue.platform}-${issue.code}`} issue={issue} actionLabel="Open settings" onAction={() => navigate(info.settingsRoute)} />)}
        </Box>
      )}

      {isDelivery && (
        <Card sx={{ overflow: 'hidden', mb: 2 }}>
          <Box sx={{ p: 2, pb: 1 }}>
            <Typography variant="h6" component="h2">{platform === 'google_ads' ? 'Conversion uploads by event' : 'Delivery by event'}</Typography>
            <Typography variant="body2" color="text.secondary">{platform === 'google_ads' ? 'Click-matched events uploaded to Google Ads' : 'Events sent to the Meta Conversions API'}</Typography>
          </Box>
          <PlatformDeliveryTable
            stats={delivery?.delivery_stats ?? []}
            totals={totals}
            loading={isFetching}
            platformLabel={info.label}
            integration={delivery?.integration}
            showOtherAccount={platform === 'google_ads'}
            showExpired={platform === 'google_ads'}
          />
        </Card>
      )}

      {platform === 'google_ads' && (
        <Card sx={{ overflow: 'hidden', mb: 2 }}>
          <Box sx={{ p: 2, pb: 0 }}>
            <Typography variant="h6" component="h2">Click matching</Typography>
            <Typography variant="body2" color="text.secondary">Storefront events whose click ID was found in the connected account's click report</Typography>
          </Box>
          <GoogleAdsAttributionTable summary={counts} loading={isFetching} />
        </Card>
      )}

      {platform === 'ga4' && (
        <Card sx={{ overflow: 'hidden', mb: 2 }}>
          <Box sx={{ p: 2, pb: 1 }}>
            <Typography variant="h6" component="h2">Events by type</Typography>
            <Typography variant="body2" color="text.secondary">Events that carry a GA4 client ID</Typography>
          </Box>
          <EventCountsTable counts={counts?.counts ?? []} total={counts?.total ?? 0} loading={isFetching} />
        </Card>
      )}

      <HowItWorks>
        {(HOW_IT_WORKS[platform] ?? []).map((p) => <Typography key={p} variant="body2" component="p">{p}</Typography>)}
      </HowItWorks>
    </PageLayout>
  );
}
