import { Box, Button, Card, Typography } from '@mui/material';

interface EmptyStateProps {
  title: string;
  description: string;
  actionLabel?: string;
  onAction?: () => void;
}

export function EmptyState({ title, description, actionLabel, onAction }: EmptyStateProps) {
  return (
    <Card sx={{ p: { xs: 3, sm: 4 }, textAlign: 'center' }}>
      <Box sx={{ maxWidth: 440, mx: 'auto' }}>
        <Typography variant="h6" component="h2">{title}</Typography>
        <Typography variant="body2" color="text.secondary" sx={{ mt: .75 }}>{description}</Typography>
        {actionLabel && onAction && <Button variant="contained" sx={{ mt: 2 }} onClick={onAction}>{actionLabel}</Button>}
      </Box>
    </Card>
  );
}
