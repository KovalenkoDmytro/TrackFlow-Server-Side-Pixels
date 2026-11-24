import { Box, Card, Typography } from '@mui/material';
import type { ReactNode } from 'react';
import { Sparkline } from './Sparkline';

interface MetricTileProps {
  label: string;
  value: ReactNode;
  caption?: ReactNode;
  series?: (number | null)[];
  color?: string;
  critical?: boolean;
}

export function MetricTile({ label, value, caption, series, color, critical = false }: MetricTileProps) {
  return (
    <Card sx={{ p: 2, minWidth: 0 }}>
      <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 550 }}>{label}</Typography>
      <Box sx={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', gap: 1 }}>
        <Box sx={{ minWidth: 0 }}>
          <Typography sx={{ fontSize: 24, lineHeight: '32px', fontWeight: 700, mt: .5, color: critical ? 'error.dark' : 'text.primary', fontVariantNumeric: 'tabular-nums' }}>{value}</Typography>
          {caption && <Typography variant="caption" color="text.secondary" component="div">{caption}</Typography>}
        </Box>
        {series && <Box sx={{ width: 110, flexShrink: 0 }}><Sparkline values={series} color={color} label={`${label} trend`} /></Box>}
      </Box>
    </Card>
  );
}
