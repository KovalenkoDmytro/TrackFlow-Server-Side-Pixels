import { ToggleButton, ToggleButtonGroup } from '@mui/material';

interface PeriodToggleProps<T extends string | number> {
  value: T;
  options: { value: T; label: string }[];
  onChange: (value: T) => void;
  ariaLabel: string;
}

/** Polaris-like segmented control. */
export function PeriodToggle<T extends string | number>({ value, options, onChange, ariaLabel }: PeriodToggleProps<T>) {
  return (
    <ToggleButtonGroup
      exclusive
      size="small"
      value={value}
      aria-label={ariaLabel}
      onChange={(_, next: T | null) => { if (next !== null) onChange(next); }}
      sx={{ p: '2px', bgcolor: '#e3e3e3', borderRadius: 2, '& .MuiToggleButtonGroup-grouped': { border: 0, borderRadius: '6px !important', px: 1.25, py: .4, color: 'text.secondary', fontSize: 12, fontWeight: 550, '&.Mui-selected': { bgcolor: '#fff', color: 'text.primary', boxShadow: '0 1px 2px rgba(0,0,0,.15)' }, '&.Mui-selected:hover': { bgcolor: '#fff' } } }}
    >
      {options.map((o) => <ToggleButton key={String(o.value)} value={o.value}>{o.label}</ToggleButton>)}
    </ToggleButtonGroup>
  );
}
