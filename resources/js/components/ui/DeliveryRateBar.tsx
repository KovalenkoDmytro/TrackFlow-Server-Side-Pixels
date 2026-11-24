import { Box, Typography } from '@mui/material';
import { formatRate } from '../../lib/deliveryRate';

/** Delivery rate (accepted / eligible). Below 98% turns amber; red stays reserved for errors. */
export function DeliveryRateBar({ value, width = 72 }: { value: number | null | undefined; width?: number }) {
  if (value === null || value === undefined) {
    return <Typography variant="body2" color="text.disabled">—</Typography>;
  }
  const color = value >= 98 ? '#29845a' : '#e5a000';
  return (
    <Box sx={{ display: 'inline-flex', alignItems: 'center', gap: 1, justifyContent: 'flex-end' }}>
      <Box sx={{ width, height: 6, borderRadius: 3, bgcolor: '#ebebeb', overflow: 'hidden' }} aria-hidden>
        <Box sx={{ width: `${Math.max(2, value)}%`, height: '100%', bgcolor: color, borderRadius: 3 }} />
      </Box>
      <Typography variant="body2" sx={{ fontVariantNumeric: 'tabular-nums', minWidth: 44, textAlign: 'right' }}>{formatRate(value)}</Typography>
    </Box>
  );
}
