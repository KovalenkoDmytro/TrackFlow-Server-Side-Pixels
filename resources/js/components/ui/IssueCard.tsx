import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Box, Button, Card, Link, Typography } from '@mui/material';
import ErrorRoundedIcon from '@mui/icons-material/ErrorRounded';
import type { DeliveryIssue } from '../../types/api';
import { PLATFORM_DOCS, guideFor } from '../../lib/errorCatalog';
import { eventChips, impactText } from '../../lib/issueImpact';
import { platformInfo, platformLabel } from '../../lib/platforms';
import { StatusBadge } from './StatusBadge';

interface IssueCardProps {
  issue: DeliveryIssue;
  /**
   * Where the card is shown. On the overview the default action opens the platform page;
   * on the platform page it opens the platform's settings. Guides that need a reconnect or
   * a new token always point to the settings of the issue's own platform.
   */
  context?: 'overview' | 'platform';
  compact?: boolean;
}

/** Actionable error card: plain-language explanation, fix steps, impact, technical details. Red is used only for errors. */
export function IssueCard({ issue, context = 'overview', compact = false }: IssueCardProps) {
  const navigate = useNavigate();
  const [showDetails, setShowDetails] = useState(false);
  const label = platformLabel(issue.platform);
  const info = platformInfo(issue.platform);
  const guide = guideFor(issue.code, issue.platform, label);
  const docs = guide.docsUrl
    ? { url: guide.docsUrl, label: guide.docsLabel ?? `${label} documentation` }
    : PLATFORM_DOCS[issue.platform];
  const chips = eventChips(issue.events);
  const impact = impactText(issue, label);

  const action = guide.cta === 'settings' || context === 'platform'
    ? (info ? { label: guide.ctaLabel ?? `Open ${label} settings`, to: info.settingsRoute } : null)
    : { label: `View ${label} details`, to: `/analytics/platform/${issue.platform}` };

  return (
    <Card sx={{ p: 2, boxShadow: 'inset 0 0 0 1px #fdb5b5, 0 1px 0 rgba(26,26,26,.07)' }}>
      <Box sx={{ display: 'flex', gap: 1.25, alignItems: 'flex-start' }}>
        <ErrorRoundedIcon sx={{ color: 'error.main', fontSize: 20, mt: '1px' }} />
        <Box sx={{ flex: 1, minWidth: 0 }}>
          <Box sx={{ display: 'flex', gap: 1, alignItems: 'center', flexWrap: 'wrap' }}>
            <Typography variant="h6" component="h3">{guide.title}</Typography>
            <StatusBadge tone="critical">{issue.count.toLocaleString()} failed</StatusBadge>
            <StatusBadge tone="neutral">{label}</StatusBadge>
            {chips.shown.map((e) => <StatusBadge key={e.event} tone="neutral">{e.label} · {e.count.toLocaleString()}</StatusBadge>)}
            {chips.hiddenEvents > 0 && <StatusBadge tone="neutral">+{chips.hiddenEvents} more · {chips.hiddenCount.toLocaleString()}</StatusBadge>}
          </Box>
          <Typography variant="body2" color="text.secondary" sx={{ mt: .5 }}>{guide.explanation}</Typography>

          {!compact && (
            <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' }, gap: 1.5, mt: 1.5 }}>
              <Box sx={{ bgcolor: '#f7f7f7', borderRadius: 2.5, p: 1.5 }}>
                <Typography variant="body2" sx={{ fontWeight: 650, mb: .5 }}>How to fix</Typography>
                <Box component="ol" sx={{ m: 0, pl: 2.25, display: 'grid', gap: .5 }}>
                  {guide.steps.map((step) => <Typography key={step} component="li" variant="body2">{step}</Typography>)}
                </Box>
              </Box>
              <Box sx={{ bgcolor: '#f7f7f7', borderRadius: 2.5, p: 1.5 }}>
                <Typography variant="body2" sx={{ fontWeight: 650, mb: .5 }}>Impact</Typography>
                <Typography variant="body2" color="text.secondary">{impact}</Typography>
              </Box>
            </Box>
          )}
          {compact && <Typography variant="body2" sx={{ mt: .5 }}>{impact}</Typography>}

          <Box sx={{ display: 'flex', gap: 1, alignItems: 'center', flexWrap: 'wrap', mt: 1.5 }}>
            {action && <Button variant="contained" size="small" onClick={() => navigate(action.to)}>{action.label}</Button>}
            <Button variant="outlined" size="small" onClick={() => setShowDetails((v) => !v)} aria-expanded={showDetails}>
              {showDetails ? 'Hide technical details' : 'Technical details'}
            </Button>
            {docs && <Link href={docs.url} target="_blank" rel="noreferrer" variant="body2" sx={{ ml: .5 }}>{docs.label}</Link>}
          </Box>
          {showDetails && (
            <Box sx={{ mt: 1.5, p: 1.5, bgcolor: '#f7f7f7', borderRadius: 2.5, fontFamily: 'ui-monospace, Menlo, monospace', fontSize: 12, color: 'text.secondary', overflowWrap: 'anywhere', whiteSpace: 'pre-wrap' }}>
              {`Code: ${issue.code}`}{issue.message ? `\nLatest message: ${issue.message}` : ''}{issue.last_seen_at ? `\nLast seen: ${new Date(issue.last_seen_at).toLocaleString()}` : ''}
            </Box>
          )}
        </Box>
      </Box>
    </Card>
  );
}
