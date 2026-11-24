<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Models\PlatformIntegration;
use App\Services\GoogleAdsClient;
use Illuminate\Console\Command;

/**
 * Manual fix for TrackFlow conversion actions created before only Purchase was primary.
 *
 * Sets primary_for_goal = false on every existing non-purchase "TF - " action that is still
 * primary, so it stops counting for bidding. Purchase is never changed; a secondary Purchase
 * action is only reported. Not scheduled: run it by hand, preferably with --dry-run first.
 */
final class SyncGoogleAdsPrimaryGoals extends Command
{
    protected $signature = 'google-ads:sync-primary-goals
        {integration? : Google Ads integration ID (default: every active Google Ads integration)}
        {--dry-run : List the actions that would change without calling the update API}';

    protected $description = 'Make existing non-purchase TrackFlow Google Ads conversion actions secondary (primary_for_goal = false)';

    public function handle(GoogleAdsClient $client): int
    {
        $integrations = PlatformIntegration::query()
            ->where('platform', Platform::GoogleAds)
            ->where('active', true)
            ->when($this->argument('integration') !== null, fn ($q) => $q->whereKey((int) $this->argument('integration')))
            ->get();

        if ($integrations->isEmpty()) {
            $this->error('No matching active Google Ads integration found.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $rows = [];
        $failed = false;

        foreach ($integrations as $integration) {
            $credentials = json_decode((string) $integration->credentials, true);

            if (! is_array($credentials) || ! isset($credentials['oauth'], $credentials['customer_id'])) {
                $rows[] = [$integration->getKey(), '-', '-', 'skipped: incomplete credentials'];

                continue;
            }

            try {
                $actions = $client->getTrackFlowConversionActionGoals($credentials);
            } catch (\Throwable $e) {
                $failed = true;
                $rows[] = [$integration->getKey(), '-', '-', 'error: '.$this->short($e)];

                continue;
            }

            foreach ($actions as $event => $action) {
                $wantPrimary = GoogleAdsClient::isPrimaryForGoal($event);

                if ($action['primary_for_goal'] === $wantPrimary) {
                    $rows[] = [$integration->getKey(), $event, $this->label($action['primary_for_goal']), 'ok'];

                    continue;
                }

                if ($wantPrimary) {
                    // Never promote automatically: the merchant may have made Purchase secondary on purpose.
                    $rows[] = [$integration->getKey(), $event, 'secondary', 'left unchanged (expected primary)'];

                    continue;
                }

                if ($dryRun) {
                    $rows[] = [$integration->getKey(), $event, 'primary', 'would set secondary'];

                    continue;
                }

                try {
                    $client->updatePrimaryForGoal($credentials, $action['resource_name'], false);
                    $rows[] = [$integration->getKey(), $event, 'primary', 'set secondary'];
                } catch (\Throwable $e) {
                    $failed = true;
                    $rows[] = [$integration->getKey(), $event, 'primary', 'error: '.$this->short($e)];
                }
            }
        }

        $this->table(['Integration', 'Event', 'Current', $dryRun ? 'Dry run' : 'Result'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function label(bool $primary): string
    {
        return $primary ? 'primary' : 'secondary';
    }

    private function short(\Throwable $e): string
    {
        return mb_substr($e->getMessage(), 0, 160);
    }
}
