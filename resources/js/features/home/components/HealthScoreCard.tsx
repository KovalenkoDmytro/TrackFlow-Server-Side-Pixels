import type { ReactNode } from 'react';
import { Box, Card, Typography } from '@mui/material';
import CheckCircleRoundedIcon from '@mui/icons-material/CheckCircleRounded';
import ErrorRoundedIcon from '@mui/icons-material/ErrorRounded';
import type { OverviewResponse } from '../../../types/api';
import { StatusBadge, type BadgeTone } from '../../../components/ui/StatusBadge';
import { platformLabel } from '../../../lib/platforms';

const STATUS: Record<OverviewResponse['health']['status'], { label: string; tone: BadgeTone; color: string }> = {
  healthy: { label: 'Healthy', tone: 'success', color: '#29845a' },
  attention: { label: 'Needs attention', tone: 'warning', color: '#e5a000' },
  critical: { label: 'Critical', tone: 'critical', color: '#e22c38' },
};

function Ring({ score, color }: { score: number; color: string }) {
  const size = 104;
  const r = size / 2 - 9;
  const c = 2 * Math.PI * r;
  return (
    <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} role="img" aria-label={`Health score ${score} out of 100`} style={{ flexShrink: 0 }}>
      <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="#ebebeb" strokeWidth={10} />
      <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke={color} strokeWidth={10} strokeLinecap="round" strokeDasharray={`${(c * score) / 100} ${c}`} transform={`rotate(-90 ${size / 2} ${size / 2})`} />
      <text x="50%" y="50%" textAnchor="middle" dominantBaseline="middle" fontSize={28} fontWeight={700} fill="#303030">{score}</text>
      <text x="50%" y="72%" textAnchor="middle" fontSize={11} fill="#616161">/ 100</text>
    </svg>
  );
}

function Line({ ok, children }: { ok: boolean; children: ReactNode }) {
  return (
    <Box sx={{ display: 'flex', gap: 1, alignItems: 'center' }}>
      {ok ? <CheckCircleRoundedIcon sx={{ fontSize: 16, color: 'success.main' }} /> : <ErrorRoundedIcon sx={{ fontSize: 16, color: 'error.main' }} />}
      <Typography variant="body2">{children}</Typography>
    </Box>
  );
}

export function HealthScoreCard({ overview }: { overview: OverviewResponse }) {
  const status = STATUS[overview.health.status];
  const connected = (['google_ads', 'meta', 'ga4'] as const).filter((k) => overview.platforms[k].connected).length;
  const failing = overview.health.deductions.filter((d) => d.key === 'delivery_failures');
  const noRecent = overview.health.deductions.some((d) => d.key === 'no_recent_events');

  return (
    <Card sx={{ p: 2, display: 'flex', gap: 2.5, alignItems: 'center', flexWrap: 'wrap' }}>
      <Ring score={overview.health.score} color={status.color} />
      <Box sx={{ flex: 1, minWidth: 200 }}>
        <Box sx={{ display: 'flex', gap: 1, alignItems: 'center' }}>
          <Typography variant="h6" component="h2">Tracking health</Typography>
          <StatusBadge tone={status.tone} dot>{status.label}</StatusBadge>
        </Box>
        <Typography variant="caption" color="text.secondary">
          {overview.last_event_at ? `Last event ${new Date(overview.last_event_at).toLocaleString()}` : 'No events received yet'}
        </Typography>
        <Box sx={{ display: 'grid', gap: .75, mt: 1.25 }}>
          <Line ok={overview.pixel_enabled}>{overview.pixel_enabled ? 'Web pixel active' : 'Web pixel is off'}</Line>
          <Line ok={connected > 0}>{connected} of 3 platforms connected</Line>
          {noRecent && <Line ok={false}>No events in the last 24 hours</Line>}
          {failing.length === 0
            ? <Line ok>No delivery problems</Line>
            : failing.map((d) => <Line key={d.platform} ok={false}>{platformLabel(d.platform ?? '')}: {d.weighted_failure_rate}% weighted failures (−{d.points})</Line>)}
        </Box>
      </Box>
    </Card>
  );
}
