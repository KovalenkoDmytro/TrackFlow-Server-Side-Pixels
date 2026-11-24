// resources/js/features/analytics/AnalyticsPage.tsx
import { useState } from 'react';
import { Alert, Box, Card, Typography } from '@mui/material';
import { PageLayout } from '../../components/ui/PageLayout';
import { PeriodToggle } from '../../components/ui/PeriodToggle';
import { EventCountsTable } from './components/EventCountsTable';
import { EventCountsChart } from './components/EventCountsChart';
import { DEFAULT_RETENTION_DAYS, PeriodControls, usePeriodState } from './components/PeriodControls';
import { useAnalytics } from './hooks/useAnalytics';
import type { AnalyticsResponse } from '../../types/api';

/** All storefront events. Platform pages live in PlatformAnalyticsPage. */
export function AnalyticsPage() {
  const period = usePeriodState();
  const [view, setView] = useState<'table' | 'chart'>('chart');
  const { data, isFetching, error } = useAnalytics(period.params);
  const summary = (data as AnalyticsResponse | undefined)?.summary;
  const counts = summary?.counts ?? [];
  const total = summary?.total ?? 0;
  const retentionDays = data?.meta?.retention_days ?? DEFAULT_RETENTION_DAYS;

  return (
    <PageLayout title="Analytics" subtitle="Events received from your store's web pixel" backTo="/">
      {error && <Alert severity="error" sx={{ mb: 2 }}>{error.message}</Alert>}
      <PeriodControls state={period} retentionDays={retentionDays} period={data?.summary?.period ?? null} loading={isFetching} />
      <Card sx={{ overflow: 'hidden' }}>
        <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 2, flexWrap: 'wrap', p: 2, pb: 1 }}>
          <Box>
            <Typography variant="h6" component="h2">Events</Typography>
            <Typography variant="body2" color="text.secondary">{total.toLocaleString()} events in the selected period</Typography>
          </Box>
          <PeriodToggle<'chart' | 'table'> ariaLabel="Analytics view" value={view} onChange={setView} options={[{ value: 'chart', label: 'Chart' }, { value: 'table', label: 'Table' }]} />
        </Box>
        {view === 'chart'
          ? <EventCountsChart counts={counts} total={total} loading={isFetching} />
          : <EventCountsTable counts={counts} total={total} loading={isFetching} />}
      </Card>
    </PageLayout>
  );
}
