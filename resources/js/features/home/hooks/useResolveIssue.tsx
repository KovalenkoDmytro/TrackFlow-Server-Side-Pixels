import { useState, type ReactNode } from 'react';
import { useMutation, useQueryClient, type QueryKey } from '@tanstack/react-query';
import { Button, Snackbar } from '@mui/material';
import { useApiClient } from '../../../hooks/useApiClient';
import { resolveIssue, unresolveIssue, type IssueKey } from '../../../api/deliveryIssues';
import { withoutIssue } from '../../../lib/issueResolution';

const ISSUE_QUERIES: QueryKey[] = [['overview'], ['analytics']];

/**
 * "Mark as resolved" with an optimistic update (the card disappears at once from the
 * overview and platform pages) and an Undo snackbar. On error the cached data is restored.
 */
export function useResolveIssue(): { resolve: (key: IssueKey) => void; pending: boolean; snackbar: ReactNode } {
  const apiClient = useApiClient();
  const queryClient = useQueryClient();
  const [notice, setNotice] = useState<{ key: IssueKey; message: string; canUndo: boolean } | null>(null);

  const refetch = () => Promise.all(ISSUE_QUERIES.map((queryKey) => queryClient.invalidateQueries({ queryKey })));

  const resolve = useMutation({
    mutationFn: (key: IssueKey) => resolveIssue(apiClient, key),
    onMutate: async (key) => {
      await Promise.all(ISSUE_QUERIES.map((queryKey) => queryClient.cancelQueries({ queryKey })));
      const snapshot = ISSUE_QUERIES.flatMap((queryKey) => queryClient.getQueriesData({ queryKey }));
      ISSUE_QUERIES.forEach((queryKey) => queryClient.setQueriesData({ queryKey }, (data: unknown) => withoutIssue(data, key)));
      setNotice({ key, message: 'Marked as resolved', canUndo: true });
      return { snapshot };
    },
    onError: (error, _key, context) => {
      context?.snapshot.forEach(([queryKey, data]) => queryClient.setQueryData(queryKey, data));
      setNotice({ key: _key, message: error instanceof Error ? error.message : 'Could not mark the issue as resolved', canUndo: false });
    },
    onSettled: refetch,
  });

  const undo = useMutation({
    mutationFn: (key: IssueKey) => unresolveIssue(apiClient, key),
    onSuccess: () => setNotice(null),
    onError: (error, key) => setNotice({ key, message: error instanceof Error ? error.message : 'Could not undo', canUndo: false }),
    onSettled: refetch,
  });

  const snackbar = (
    <Snackbar
      open={notice !== null}
      autoHideDuration={notice?.canUndo ? 8000 : 5000}
      onClose={(_, reason) => { if (reason !== 'clickaway') setNotice(null); }}
      message={notice?.message}
      anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
      action={notice?.canUndo
        ? <Button size="small" sx={{ color: '#fff' }} disabled={undo.isPending} onClick={() => notice && undo.mutate(notice.key)}>Undo</Button>
        : undefined}
    />
  );

  return { resolve: (key) => resolve.mutate(key), pending: resolve.isPending, snackbar };
}
