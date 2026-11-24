import { useEffect } from 'react';
import { Box, Typography } from '@mui/material';
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { NavMenu } from '@shopify/app-bridge-react';

const links = [
  { to: '/', label: 'Overview' },
  { to: '/analytics', label: 'Analytics' },
];

/** App Bridge is loaded in the embedded admin; it is skipped in local dev auth bypass. */
function hasAppBridge(): boolean {
  return typeof window !== 'undefined' && Boolean((window as { shopify?: unknown }).shopify);
}

function Main() {
  return (
    <Box component="main" sx={{ px: { xs: 2, sm: 3, lg: 4 }, py: { xs: 2, md: 3 } }}>
      <Box sx={{ maxWidth: 1200, mx: 'auto' }}><Outlet /></Box>
    </Box>
  );
}

export function AppShell() {
  const { pathname } = useLocation();
  const navigate = useNavigate();
  const embedded = hasAppBridge();

  // Items in the Shopify admin nav menu dispatch `shopify:navigate`; route them through React Router.
  useEffect(() => {
    if (!embedded) return undefined;
    const onNavigate = (event: Event) => {
      const target = event.target as HTMLElement | null;
      const href = target?.getAttribute('href');
      if (href) navigate(href);
    };
    document.addEventListener('shopify:navigate', onNavigate);
    return () => document.removeEventListener('shopify:navigate', onNavigate);
  }, [embedded, navigate]);

  if (embedded) {
    // Built for Shopify 4.1.4: primary navigation lives in the Shopify admin nav menu.
    // rel="home" makes the app name point at the overview instead of adding a duplicate item.
    return (
      <Box sx={{ minHeight: '100vh', bgcolor: 'background.default' }}>
        <NavMenu>
          <a href="/" rel="home">Overview</a>
          <a href="/analytics">Analytics</a>
        </NavMenu>
        <Main />
      </Box>
    );
  }

  // Local development without App Bridge: a minimal neutral header so pages stay reachable.
  return (
    <Box sx={{ minHeight: '100vh', bgcolor: 'background.default' }}>
      <Box component="header" sx={{ bgcolor: '#fff', borderBottom: '1px solid', borderColor: 'divider', px: { xs: 2, sm: 3, lg: 4 } }}>
        <Box sx={{ maxWidth: 1200, mx: 'auto', minHeight: 52, display: 'flex', alignItems: 'center', gap: 4 }}>
          <Typography sx={{ fontWeight: 700, fontSize: 14 }}>TrackFlow</Typography>
          <Box component="nav" aria-label="Main navigation" sx={{ display: 'flex', alignSelf: 'stretch', gap: 3 }}>
            {links.map(({ to, label }) => {
              const active = to === '/' ? pathname === '/' || pathname.startsWith('/settings') : pathname.startsWith('/analytics');
              return <Box key={to} component={NavLink} to={to} sx={{ display: 'flex', alignItems: 'center', borderBottom: '2px solid', borderColor: active ? 'text.primary' : 'transparent', color: active ? 'text.primary' : 'text.secondary', textDecoration: 'none', fontSize: 13, fontWeight: active ? 650 : 500 }}>{label}</Box>;
            })}
          </Box>
          <Typography variant="caption" color="text.secondary" sx={{ ml: 'auto' }}>Local dev · App Bridge not loaded</Typography>
        </Box>
      </Box>
      <Main />
    </Box>
  );
}
