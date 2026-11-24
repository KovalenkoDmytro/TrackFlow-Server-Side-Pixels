// resources/js/main.tsx
import { createRoot } from 'react-dom/client';
import { RouterProvider } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { CssBaseline, ThemeProvider, createTheme } from '@mui/material';
import { router } from './router/index';
import { SEGMENTED_PILL_RADIUS, SEGMENTED_TRACK_PADDING, SEGMENTED_TRACK_RADIUS } from './components/ui/segmented';

// Polaris-like tokens so the embedded app feels native in the Shopify admin
// (Built for Shopify 4.1.1: admin-like cards, neutral primary buttons, admin background and text size).
const CARD_SHADOW = '0 1px 0 0 rgba(26,26,26,.07), inset 0 1px 0 0 rgba(204,204,204,.5), inset 0 -1px 0 0 rgba(0,0,0,.17), inset -1px 0 0 0 rgba(0,0,0,.13), inset 1px 0 0 0 rgba(0,0,0,.13)';
const CARD_EDGE = {
  position: 'relative' as const,
  boxShadow: '0 1px 0 0 rgba(26,26,26,.07)',
  '&::after': { content: '""', position: 'absolute' as const, inset: 0, borderRadius: 'inherit', boxShadow: CARD_SHADOW, pointerEvents: 'none' as const, zIndex: 1 },
};

const theme = createTheme({
  palette: {
    primary: { main: '#303030', dark: '#1a1a1a', light: '#f1f1f1', contrastText: '#ffffff' },
    success: { main: '#29845a', light: '#cdfee1', dark: '#0c5132' },
    warning: { main: '#e5a000', light: '#fff1c7', dark: '#4f4700' },
    error: { main: '#e22c38', light: '#fedad9', dark: '#8e0b21' },
    info: { main: '#2c6ecb', light: '#e0f0ff', dark: '#00527c' },
    background: { default: '#f1f1f1', paper: '#ffffff' },
    text: { primary: '#303030', secondary: '#616161', disabled: '#b5b5b5' },
    divider: '#e3e3e3',
  },
  shape: { borderRadius: 8 },
  typography: {
    fontFamily: 'Inter, -apple-system, BlinkMacSystemFont, "San Francisco", "Segoe UI", Roboto, "Helvetica Neue", sans-serif',
    fontSize: 13,
    htmlFontSize: 16,
    body1: { fontSize: '0.8125rem', lineHeight: 1.54 },
    body2: { fontSize: '0.8125rem', lineHeight: 1.54 },
    caption: { fontSize: '0.75rem', lineHeight: 1.33 },
    h4: { fontSize: '1.5rem', fontWeight: 700, letterSpacing: '-.01em' },
    h5: { fontSize: '1.25rem', fontWeight: 700, letterSpacing: '-.01em' },
    h6: { fontSize: '0.875rem', fontWeight: 650, letterSpacing: 0 },
    subtitle1: { fontSize: '0.875rem', fontWeight: 650 },
    button: { textTransform: 'none', fontWeight: 600, letterSpacing: 0, fontSize: '0.75rem' },
  },
  components: {
    MuiCssBaseline: { styleOverrides: { body: { backgroundColor: '#f1f1f1', WebkitFontSmoothing: 'antialiased' } } },
    // The card edge is drawn by an overlay above the content: with an inset box-shadow alone,
    // full-bleed children (table header rows) paint over the edge and look glued to the side.
    MuiCard: { styleOverrides: { root: { borderRadius: 12, border: 0, ...CARD_EDGE } } },
    MuiPaper: { styleOverrides: { outlined: { border: 0, ...CARD_EDGE } } },
    MuiButton: {
      defaultProps: { disableElevation: true },
      styleOverrides: {
        root: { borderRadius: 8, minHeight: 30, paddingInline: 12 },
        contained: { boxShadow: 'inset 0 -1px 0 1px #000, inset 0 1px 0 0 rgba(255,255,255,.25)', '&:hover': { backgroundColor: '#1a1a1a' } },
        outlined: { backgroundColor: '#fff', color: '#303030', border: 0, boxShadow: 'inset 0 1px 0 0 #e3e3e3, inset 0 -1px 0 0 #b5b5b5, 0 0 0 1px #d4d4d4', '&:hover': { backgroundColor: '#fafafa', border: 0 } },
        text: { color: '#005bd3', '&:hover': { backgroundColor: 'rgba(0,91,211,.06)' } },
      },
    },
    MuiOutlinedInput: { styleOverrides: { root: { borderRadius: 8, backgroundColor: '#fff' } } },
    MuiChip: { styleOverrides: { root: { borderRadius: 8, fontWeight: 550, height: 22, fontSize: 12 } } },
    MuiAlert: { styleOverrides: { root: { borderRadius: 12, fontSize: '0.8125rem' } } },
    MuiTableCell: {
      styleOverrides: {
        root: { borderColor: '#ebebeb', fontSize: '0.8125rem' },
        head: { backgroundColor: '#f7f7f7', color: '#616161', fontWeight: 550, fontSize: '0.75rem', whiteSpace: 'nowrap' },
        footer: { color: '#303030', fontSize: '0.8125rem' },
      },
    },
    MuiToggleButton: { styleOverrides: { root: { textTransform: 'none' } } },
    MuiToggleButtonGroup: {
      styleOverrides: {
        // Explicit px values: a unitless sx borderRadius is multiplied by shape.borderRadius,
        // which is what made the track (16px) and the pill (6px) mismatch.
        root: { padding: SEGMENTED_TRACK_PADDING, borderRadius: SEGMENTED_TRACK_RADIUS, backgroundColor: '#e3e3e3' },
        grouped: {
          border: 0,
          borderRadius: `${SEGMENTED_PILL_RADIUS}px !important`,
          margin: 0,
          padding: '3px 10px',
          color: '#616161',
          fontSize: 12,
          fontWeight: 550,
          '&.Mui-selected, &.Mui-selected:hover': { backgroundColor: '#fff', color: '#303030', boxShadow: '0 1px 2px rgba(0,0,0,.15)' },
        },
      },
    },
    MuiAccordion: { styleOverrides: { root: { borderRadius: 12, boxShadow: CARD_SHADOW, '&:before': { display: 'none' } } } },
    MuiSwitch: { styleOverrides: { switchBase: { '&.Mui-checked': { color: '#fff' }, '&.Mui-checked + .MuiSwitch-track': { backgroundColor: '#303030', opacity: 1 } } } },
  },
});

const queryClient = new QueryClient({
  defaultOptions: {
    queries: { retry: 1, staleTime: 30_000 },
  },
});

const container = document.getElementById('root');
if (!container) throw new Error('Root element not found');

createRoot(container).render(
  <QueryClientProvider client={queryClient}>
    <ThemeProvider theme={theme}>
      <CssBaseline />
      <RouterProvider router={router} />
    </ThemeProvider>
  </QueryClientProvider>,
);
