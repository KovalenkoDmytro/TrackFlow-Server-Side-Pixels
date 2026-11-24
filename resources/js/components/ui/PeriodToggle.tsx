import { ToggleButton, ToggleButtonGroup } from '@mui/material';

interface PeriodToggleProps<T extends string | number> {
  value: T;
  options: { value: T; label: string }[];
  onChange: (value: T) => void;
  ariaLabel: string;
}

/** Polaris-like segmented control. Track and pill geometry live in the theme (MuiToggleButtonGroup, see segmented.ts). */
export function PeriodToggle<T extends string | number>({ value, options, onChange, ariaLabel }: PeriodToggleProps<T>) {
  return (
    <ToggleButtonGroup
      exclusive
      size="small"
      value={value}
      aria-label={ariaLabel}
      onChange={(_, next: T | null) => { if (next !== null) onChange(next); }}
    >
      {options.map((o) => <ToggleButton key={String(o.value)} value={o.value}>{o.label}</ToggleButton>)}
    </ToggleButtonGroup>
  );
}
