import { Accordion, AccordionDetails, AccordionSummary, Typography } from '@mui/material';
import ExpandMoreRoundedIcon from '@mui/icons-material/ExpandMoreRounded';
import type { ReactNode } from 'react';

/** Collapsed-by-default explanation, so long copy does not crowd the page (Built for Shopify 4.3.4). */
export function HowItWorks({ title = 'How it works', children, defaultExpanded = false }: { title?: string; children: ReactNode; defaultExpanded?: boolean }) {
  return (
    <Accordion disableGutters defaultExpanded={defaultExpanded} sx={{ mt: 2 }}>
      <AccordionSummary expandIcon={<ExpandMoreRoundedIcon />}>
        <Typography variant="h6" component="h2">{title}</Typography>
      </AccordionSummary>
      <AccordionDetails sx={{ pt: 0, color: 'text.secondary', '& p + p': { mt: 1 } }}>{children}</AccordionDetails>
    </Accordion>
  );
}
