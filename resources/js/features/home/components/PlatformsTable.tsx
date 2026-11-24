import { Box, Button, Card, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, Typography } from '@mui/material';
import type { OverviewResponse } from '../../../types/api';
import { PLATFORMS } from '../../../lib/platforms';
import { StatusBadge } from '../../../components/ui/StatusBadge';
import { DeliveryRateBar } from '../../../components/ui/DeliveryRateBar';

interface PlatformsTableProps {
  overview?: OverviewResponse;
  integrations: Record<string, boolean>;
  onNavigate: (route: string) => void;
}

export function PlatformsTable({ overview, integrations, onNavigate }: PlatformsTableProps) {
  const failedBy = (key: string) => overview?.issues.filter((i) => i.platform === key).reduce((sum, i) => sum + i.count, 0) ?? 0;

  return (
    <Card sx={{ overflow: 'hidden' }}>
      <Box sx={{ p: 2, pb: 1.5 }}>
        <Typography variant="h6" component="h2">Platforms</Typography>
        <Typography variant="body2" color="text.secondary">Server-side delivery for the selected period</Typography>
      </Box>
      <TableContainer sx={{ overflowX: 'auto' }}>
        <Table size="small" sx={{ minWidth: 640 }}>
          <TableHead>
            <TableRow>
              <TableCell>Platform</TableCell>
              <TableCell>Status</TableCell>
              <TableCell align="right">Volume</TableCell>
              <TableCell align="right">Delivery rate</TableCell>
              <TableCell align="right">Issues</TableCell>
              <TableCell />
            </TableRow>
          </TableHead>
          <TableBody>
            {PLATFORMS.map((p) => {
              const connected = Boolean(integrations[p.key]);
              const delivery = p.key === 'google_ads' || p.key === 'meta' ? overview?.platforms[p.key] : undefined;
              const failed = failedBy(p.key);
              let volume = '—';
              if (delivery) volume = `${delivery.attempted.toLocaleString()} ${p.key === 'google_ads' ? 'uploads' : 'events'}`;
              if (p.key === 'ga4' && overview) volume = `${overview.platforms.ga4.events.toLocaleString()} events`;

              return (
                <TableRow key={p.key}>
                  <TableCell>
                    <Box sx={{ display: 'flex', gap: 1.25, alignItems: 'center' }}>
                      <Box sx={{ width: 28, height: 28, borderRadius: 2, bgcolor: p.bg, color: p.color, display: 'grid', placeItems: 'center', fontWeight: 700, flexShrink: 0 }}>{p.initial}</Box>
                      <Box>
                        <Typography variant="body2" sx={{ fontWeight: 600 }}>{p.label}</Typography>
                        <Typography variant="caption" color="text.secondary">{p.api}</Typography>
                      </Box>
                    </Box>
                  </TableCell>
                  <TableCell>
                    {p.comingSoon ? <StatusBadge>Coming soon</StatusBadge> : connected ? <StatusBadge tone="success" dot>Connected</StatusBadge> : <StatusBadge>Not connected</StatusBadge>}
                  </TableCell>
                  <TableCell align="right">{p.comingSoon || !connected ? '—' : volume}</TableCell>
                  <TableCell align="right">
                    {delivery && connected ? <DeliveryRateBar value={delivery.delivery_rate} /> : p.key === 'ga4' && connected ? <Typography variant="body2" color="text.secondary">Not reported</Typography> : <Typography variant="body2" color="text.disabled">—</Typography>}
                  </TableCell>
                  <TableCell align="right">{failed > 0 ? <StatusBadge tone="critical">{failed.toLocaleString()} failed</StatusBadge> : <Typography variant="body2" color="text.disabled">—</Typography>}</TableCell>
                  <TableCell align="right" sx={{ whiteSpace: 'nowrap' }}>
                    {!p.comingSoon && connected && <Button size="small" variant="text" onClick={() => onNavigate(`/analytics/platform/${p.key}`)} sx={{ mr: .5 }}>View events</Button>}
                    {!p.comingSoon && <Button size="small" variant="outlined" onClick={() => onNavigate(p.settingsRoute)}>{connected ? 'Manage' : 'Connect'}</Button>}
                  </TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      </TableContainer>
    </Card>
  );
}
