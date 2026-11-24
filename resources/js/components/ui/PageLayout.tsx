// resources/js/components/ui/PageLayout.tsx
import { Box, IconButton, Typography } from '@mui/material';
import ArrowBackRoundedIcon from '@mui/icons-material/ArrowBackRounded';
import { useNavigate } from 'react-router-dom';
import type { ReactNode } from 'react';

interface PageLayoutProps {
  title: string;
  /** Short line under the title. */
  subtitle?: ReactNode;
  /** Shown next to the title, e.g. a status badge. */
  titleMeta?: ReactNode;
  maxWidth?: number;
  backTo?: string;
  actions?: ReactNode;
  children: ReactNode;
}

/** Polaris-style page header: back arrow, title, optional badge, actions on the right. */
export function PageLayout({ title, subtitle, titleMeta, maxWidth = 1200, backTo, actions, children }: PageLayoutProps) {
  const navigate = useNavigate();

  return (
    <Box sx={{ width: '100%', maxWidth, mx: 'auto' }}>
      <Box sx={{ display: 'flex', alignItems: { xs: 'flex-start', sm: 'center' }, justifyContent: 'space-between', gap: 2, mb: 2.5, flexWrap: 'wrap' }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, minWidth: 0 }}>
          {backTo && (
            <IconButton size="small" aria-label="Back" onClick={() => navigate(backTo)} sx={{ borderRadius: 2, color: 'text.secondary' }}>
              <ArrowBackRoundedIcon fontSize="small" />
            </IconButton>
          )}
          <Box sx={{ minWidth: 0 }}>
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, flexWrap: 'wrap' }}>
              <Typography variant="h5" component="h1">{title}</Typography>
              {titleMeta}
            </Box>
            {subtitle && <Typography variant="body2" color="text.secondary" sx={{ mt: .25 }}>{subtitle}</Typography>}
          </Box>
        </Box>
        {actions && <Box sx={{ display: 'flex', gap: 1, alignItems: 'center', flexWrap: 'wrap' }}>{actions}</Box>}
      </Box>
      {children}
    </Box>
  );
}
