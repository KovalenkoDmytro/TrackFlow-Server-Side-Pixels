import { useState, type ReactNode } from 'react';
import { useMutation, useQueryClient, type QueryKey } from '@tanstack/react-query';
import { Button, Snackbar } from '@mui/material';
import { useApiClient } from '../../../hooks/useApiClient';
import { reportIssue, resolveIssue, unresolveIssue, type IssueKey } from '../../../api/deliveryIssues';
import { withoutIssue } from '../../../lib/issueResolution';
import { withReported } from '../../../lib/issueSupport';

const ISSUE_QUERIES: QueryKey[] = [['overview'], ['analytics']];

/**
 * Issue card actions, both with optimistic updates that are rolled back on error:
 * - "Mark as resolved": the card disappears at once, with an Undo snackbar.
 * - "Send to support": the card switches to its calm "Sent to support" state.
 */
export function useResolveIssue(): {
  resolve: (key: IssueKey) => void;
  report: (key: IssueKey, note?: string) => void;
  pending: boolean;
  snackbar: ReactNode;
} {
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

  const report = useMutation({
    mutationFn: ({ key, note }: { key: IssueKey; note?: string }) => reportIssue(apiClient, key, note),
    onMutate: async ({ key }) => {
      await Promise.all(ISSUE_QUERIES.map((queryKey) => queryClient.cancelQueries({ queryKey })));
      const snapshot = ISSUE_QUERIES.flatMap((queryKey) => queryClient.getQueriesData({ queryKey }));
      const now = new Date().toISOString();
      ISSUE_QUERIES.forEach((queryKey) => queryClient.setQueriesData({ queryKey }, (data: unknown) => withReported(data, key, now)));
      return { snapshot };
    },
    onSuccess: (_result, { key }) => setNotice({ key, message: 'Sent to support', canUndo: false }),
    onError: (error, { key }, context) => {
      context?.snapshot.forEach(([queryKey, data]) => queryClient.setQueryData(queryKey, data));
      setNotice({ key, message: error instanceof Error ? error.message : 'Could not send to support', canUndo: false });
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

  return {
    resolve: (key) => resolve.mutate(key),
    report: (key, note) => report.mutate({ key, note }),
    pending: resolve.isPending || report.isPending,
    snackbar,
  };
}
