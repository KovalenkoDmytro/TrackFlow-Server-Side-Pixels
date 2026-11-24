import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Alert, Box, Button, Typography } from '@mui/material';
import { useShopStatus } from './hooks/useShopStatus';
import { useOverview } from './hooks/useOverview';
import { LoadingState } from '../../components/ui/LoadingState';
import { PageLayout } from '../../components/ui/PageLayout';
import { PeriodToggle } from '../../components/ui/PeriodToggle';
import { MetricTile } from '../../components/ui/MetricTile';
import { IssueCard } from '../../components/ui/IssueCard';
import { EmptyState } from '../../components/ui/EmptyState';
import { PixelStatusCard } from './components/PixelStatusCard';
import { HealthScoreCard } from './components/HealthScoreCard';
import { PlatformsTable } from './components/PlatformsTable';
import { formatRate } from '../../lib/deliveryRate';
import { platformLabel } from '../../lib/platforms';

type Days = 7 | 30 | 90;

export function HomePage() {
  const navigate = useNavigate();
  const [days, setDays] = useState<Days>(30);
  const shop = useShopStatus();
  const overview = useOverview(days);

  if (shop.isLoading) return <LoadingState />;
  if (shop.error) return <Alert severity="error">{shop.error.message}</Alert>;

  const integrations = shop.data?.integrations ?? {};
  const pixelEnabled = Boolean(shop.data?.shop?.pixel_enabled);
  const data = overview.data;
  const connectedCount = ['google_ads', 'meta', 'ga4'].filter((k) => integrations[k]).length;
  const rangeLabel = `last ${days} days`;

  return (
    <PageLayout
      title="Overview"
      subtitle={shop.data?.shop?.name}
      actions={<>
        <PeriodToggle<Days> ariaLabel="Overview period" value={days} onChange={setDays} options={[{ value: 7, label: '7 days' }, { value: 30, label: '30 days' }, { value: 90, label: '90 days' }]} />
        <Button variant="contained" onClick={() => navigate('/analytics')}>View analytics</Button>
      </>}
    >
      {overview.error && <Alert severity="error" sx={{ mb: 2 }}>{overview.error.message}</Alert>}

      {data && !data.has_data && (
        <Box sx={{ mb: 2 }}>
          <EmptyState
            title={!pixelEnabled ? 'Turn on the web pixel to start tracking' : connectedCount === 0 ? 'Connect your first ad platform' : `No events in the ${rangeLabel}`}
            description={!pixelEnabled
              ? 'TrackFlow collects storefront events with a Shopify web pixel. Turn it on below, then health, delivery and purchase metrics appear here.'
              : connectedCount === 0
                ? 'Connect Google Ads, Meta or GA4 so TrackFlow can send your store events to them server-side.'
                : 'Your pixel is on. Metrics appear here as soon as visitors browse your store.'}
            actionLabel={pixelEnabled && connectedCount === 0 ? 'Connect Google Ads' : undefined}
            onAction={pixelEnabled && connectedCount === 0 ? () => navigate('/settings/google-ads') : undefined}
          />
        </Box>
      )}

      {overview.isLoading && <LoadingState />}

      {data && data.has_data && (
        <>
          <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', lg: '1.05fr 2fr' }, gap: 2, mb: 2 }}>
            <HealthScoreCard overview={data} />
            <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' }, gap: 2 }}>
              <MetricTile label="Events received" value={data.totals.events.toLocaleString()} caption={`From the web pixel, ${rangeLabel}`} series={data.series.events} />
              <MetricTile
                label="Delivery rate"
                value={formatRate(data.totals.delivery_rate)}
                caption={(['google_ads', 'meta'] as const).filter((k) => data.platforms[k].connected).map((k) => `${platformLabel(k)} ${formatRate(data.platforms[k].delivery_rate)}`).join(' · ') || 'Google Ads and Meta'}
                series={data.series.delivery_rate}
                color="#29845a"
              />
              <MetricTile label="Purchases tracked" value={data.totals.purchases.toLocaleString()} caption={`Orders seen by the pixel, ${rangeLabel}`} series={data.series.purchases} />
              <MetricTile label="Failed uploads" value={data.totals.failed.toLocaleString()} caption={data.issues.length > 0 ? `${data.issues.length} issue${data.issues.length === 1 ? '' : 's'} to review` : 'Google Ads and Meta'} series={data.series.failed} color="#e22c38" critical={data.totals.failed > 0} />
            </Box>
          </Box>
          <Typography variant="caption" color="text.secondary" component="p" sx={{ mb: 2, mt: -1 }}>
            Delivery rate = accepted ÷ eligible uploads to Google Ads and Meta (skipped and pending excluded). GA4 does not report delivery and is not part of the health score.
          </Typography>

          {data.issues.length > 0 && (
            <Box sx={{ display: 'grid', gap: 2, mb: 2 }}>
              {data.issues.slice(0, 3).map((issue) => (
                <IssueCard key={`${issue.platform}-${issue.code}`} issue={issue} compact actionLabel={`Review in ${platformLabel(issue.platform)}`} onAction={() => navigate(`/analytics/platform/${issue.platform}`)} />
              ))}
            </Box>
          )}
        </>
      )}

      <Box sx={{ mb: 2 }}>
        <PixelStatusCard pixelEnabled={pixelEnabled} pixelId={shop.data?.shop?.shopify_pixel_id} />
      </Box>

      <PlatformsTable overview={data} integrations={integrations} onNavigate={navigate} />
    </PageLayout>
  );
}
