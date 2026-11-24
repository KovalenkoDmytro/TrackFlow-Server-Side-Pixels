// resources/js/features/home/hooks/useOverview.ts
import { useQuery } from '@tanstack/react-query';
import { useApiClient } from '../../../hooks/useApiClient';
import { getOverview } from '../../../api/overview';

export function useOverview(days: number) {
  const apiClient = useApiClient();
  return useQuery({
    queryKey: ['overview', days],
    queryFn: () => getOverview(apiClient, days),
  });
}
