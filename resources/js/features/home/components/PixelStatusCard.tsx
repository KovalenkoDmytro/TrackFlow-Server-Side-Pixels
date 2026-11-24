import { useState } from 'react';
import { Alert, Box, Card, CircularProgress, Switch, Typography } from '@mui/material';
import SensorsRoundedIcon from '@mui/icons-material/SensorsRounded';
import { usePixelToggle } from '../hooks/usePixelToggle';

interface PixelStatusCardProps { pixelEnabled: boolean; pixelId: string | null | undefined; }

export function PixelStatusCard({ pixelEnabled, pixelId }: PixelStatusCardProps) {
  const toggleMutation = usePixelToggle();
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const handleChange = (_event: unknown, checked: boolean) => {
    setErrorMessage(null);
    toggleMutation.mutate(checked, { onError: (error) => setErrorMessage(error instanceof Error ? error.message : 'Failed to update pixel status.') });
  };
  return (
    <Card variant="outlined" sx={{ p: 2 }}>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.75, flexWrap: 'wrap' }}>
        <Box sx={{ width: 48, height: 48, flexShrink: 0, borderRadius: '14px', display: 'grid', placeItems: 'center', bgcolor: pixelEnabled ? '#cdfee1' : '#f1f1f1', color: pixelEnabled ? '#0c5132' : '#8a8a8a' }}><SensorsRoundedIcon /></Box>
        <Box sx={{ flex: 1, minWidth: 180 }}><Typography sx={{ fontSize: 14, fontWeight: 750 }}>Shopify web pixel</Typography><Typography sx={{ fontSize: 12, color: '#616161', mt: .4 }}>{pixelEnabled ? 'Your pixel is active and collecting events.' : 'Turn on your pixel to start collecting events.'}</Typography>{pixelEnabled && pixelId && <Typography sx={{ fontSize: 11, color: '#8a8a8a', mt: .5, overflowWrap: 'anywhere' }}>ID: {pixelId}</Typography>}</Box>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}><Typography sx={{ fontSize: 12, color: pixelEnabled ? '#0c5132' : '#616161', fontWeight: 600 }}>{pixelEnabled ? 'Active' : 'Inactive'}</Typography>{toggleMutation.isPending && <CircularProgress size={16} />}<Switch checked={pixelEnabled} disabled={toggleMutation.isPending} onChange={handleChange} slotProps={{ input: { 'aria-label': 'Toggle tracking pixel' } }} /></Box>
      </Box>
      {errorMessage && <Alert severity="error" sx={{ mt: 2 }}>{errorMessage}</Alert>}
    </Card>
  );
}
