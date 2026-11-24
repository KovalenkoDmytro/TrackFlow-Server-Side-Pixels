import { Box } from '@mui/material';
import type { ReactNode } from 'react';

export type BadgeTone = 'success' | 'warning' | 'critical' | 'info' | 'neutral';

const TONES: Record<BadgeTone, { bg: string; fg: string }> = {
  success: { bg: '#cdfee1', fg: '#0c5132' },
  warning: { bg: '#ffeb78', fg: '#4f4700' },
  critical: { bg: '#fedad9', fg: '#8e0b21' },
  info: { bg: '#e0f0ff', fg: '#00527c' },
  neutral: { bg: '#e3e3e3', fg: '#616161' },
};

export function StatusBadge({ tone = 'neutral', children, dot = false }: { tone?: BadgeTone; children: ReactNode; dot?: boolean }) {
  const { bg, fg } = TONES[tone];
  return (
    <Box component="span" sx={{ display: 'inline-flex', alignItems: 'center', gap: .5, height: 20, px: 1, borderRadius: 2, bgcolor: bg, color: fg, fontSize: 12, fontWeight: 550, whiteSpace: 'nowrap' }}>
      {dot && <Box component="span" sx={{ width: 6, height: 6, borderRadius: '50%', bgcolor: 'currentColor' }} />}
      {children}
    </Box>
  );
}
