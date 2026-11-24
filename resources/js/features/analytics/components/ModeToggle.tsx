import { PeriodToggle } from '../../../components/ui/PeriodToggle';
import type { AnalyticsMode } from '../../../types/api';

interface ModeToggleProps {
  mode: AnalyticsMode;
  onChange: (mode: AnalyticsMode) => void;
}

export function ModeToggle({ mode, onChange }: ModeToggleProps) {
  return <PeriodToggle<AnalyticsMode> ariaLabel="Date selection mode" value={mode} onChange={onChange} options={[{ value: 'single_day', label: 'Single day' }, { value: 'range', label: 'Date range' }]} />;
}
