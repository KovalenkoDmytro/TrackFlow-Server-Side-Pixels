import { useState } from 'react';
import { Box, Card, Typography } from '@mui/material';
import { ModeToggle } from './ModeToggle';
import { DayNavigator } from './DayNavigator';
import { DateRangePicker } from './DateRangePicker';
import type { AnalyticsMode, AnalyticsParams, AnalyticsPeriod } from '../../../types/api';

// Fallback used only until the first API response arrives; the API's `meta.retention_days`
// (sourced from config('tracking.retention_days')) is the source of truth thereafter.
export const DEFAULT_RETENTION_DAYS = 90;

function todayString(): string {
  const date = new Date();
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

export function usePeriodState(platform?: string) {
  const today = todayString();
  const [mode, setMode] = useState<AnalyticsMode>('single_day');
  const [date, setDate] = useState(today);
  const [startDate, setStartDate] = useState(today);
  const [endDate, setEndDate] = useState(today);

  const params: AnalyticsParams = mode === 'single_day'
    ? { mode, date, platform }
    : { mode, start_date: startDate, end_date: endDate, platform };

  return { mode, setMode, date, setDate, startDate, endDate, setRange: (s: string, e: string) => { setStartDate(s); setEndDate(e); }, params };
}

type PeriodState = ReturnType<typeof usePeriodState>;

export function PeriodControls({ state, retentionDays, period, loading }: { state: PeriodState; retentionDays: number; period: AnalyticsPeriod | null; loading: boolean }) {
  return (
    <Card sx={{ p: 2, mb: 2 }}>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, flexWrap: 'wrap' }}>
        <ModeToggle mode={state.mode} onChange={state.setMode} />
        {state.mode === 'single_day'
          ? <DayNavigator date={state.date} onChange={state.setDate} retentionDays={retentionDays} />
          : <DateRangePicker startDate={state.startDate} endDate={state.endDate} onApply={state.setRange} retentionDays={retentionDays} />}
        <Typography variant="caption" color="text.secondary" sx={{ ml: { md: 'auto' } }}>
          {period && !loading ? `Showing ${period.label}${period.days > 1 ? ` (${period.days} days)` : ''} · ` : ''}Data retained for {retentionDays} days
        </Typography>
      </Box>
    </Card>
  );
}
