import { useState } from 'react';
import { Box, Button, Card, Link, Typography } from '@mui/material';
import ErrorRoundedIcon from '@mui/icons-material/ErrorRounded';
import type { DeliveryIssue } from '../../types/api';
import { guideFor } from '../../lib/errorCatalog';
import { platformLabel } from '../../lib/platforms';
import { StatusBadge } from './StatusBadge';

interface IssueCardProps {
  issue: DeliveryIssue;
  /** Main action, e.g. open the platform page or its settings. */
  actionLabel?: string;
  onAction?: () => void;
  compact?: boolean;
}

const HIGH_VALUE = new Set(['purchase', 'begin_checkout', 'add_payment_info', 'add_shipping_info', 'add_to_cart']);

function impactText(issue: DeliveryIssue, label: string): string {
  const purchases = issue.events.find((e) => e.event === 'purchase')?.count ?? 0;
  if (purchases > 0) {
    return `${purchases.toLocaleString()} purchase${purchases === 1 ? '' : 's'} not received by ${label}. This affects reported conversions and bidding.`;
  }
  if (issue.events.some((e) => HIGH_VALUE.has(e.event))) {
    return `Cart or checkout events are affected. Purchases are not affected.`;
  }
  return `Only browse events are affected. Purchases are not affected, and bidding is unaffected unless you optimise for these events.`;
}

/** Actionable error card: plain-language explanation, fix steps, impact, technical details. Red is used only for errors. */
export function IssueCard({ issue, actionLabel, onAction, compact = false }: IssueCardProps) {
  const [showDetails, setShowDetails] = useState(false);
  const label = platformLabel(issue.platform);
  const guide = guideFor(issue.code, label);

  return (
    <Card sx={{ p: 2, boxShadow: 'inset 0 0 0 1px #fdb5b5, 0 1px 0 rgba(26,26,26,.07)' }}>
      <Box sx={{ display: 'flex', gap: 1.25, alignItems: 'flex-start' }}>
        <ErrorRoundedIcon sx={{ color: 'error.main', fontSize: 20, mt: '1px' }} />
        <Box sx={{ flex: 1, minWidth: 0 }}>
          <Box sx={{ display: 'flex', gap: 1, alignItems: 'center', flexWrap: 'wrap' }}>
            <Typography variant="h6" component="h3">{guide.title}</Typography>
            <StatusBadge tone="critical">{issue.count.toLocaleString()} failed</StatusBadge>
            <StatusBadge tone="neutral">{label}</StatusBadge>
            {issue.events.slice(0, 3).map((e) => <StatusBadge key={e.event} tone="neutral">{e.label} · {e.count.toLocaleString()}</StatusBadge>)}
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
                <Typography variant="body2" color="text.secondary">{impactText(issue, label)}</Typography>
              </Box>
            </Box>
          )}
          {compact && <Typography variant="body2" sx={{ mt: .5 }}>{impactText(issue, label)}</Typography>}

          <Box sx={{ display: 'flex', gap: 1, alignItems: 'center', flexWrap: 'wrap', mt: 1.5 }}>
            {actionLabel && onAction && <Button variant="contained" size="small" onClick={onAction}>{actionLabel}</Button>}
            <Button variant="outlined" size="small" onClick={() => setShowDetails((v) => !v)} aria-expanded={showDetails}>
              {showDetails ? 'Hide technical details' : 'Technical details'}
            </Button>
            {guide.docsUrl && <Link href={guide.docsUrl} target="_blank" rel="noreferrer" variant="body2" sx={{ ml: .5 }}>{label} documentation</Link>}
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
