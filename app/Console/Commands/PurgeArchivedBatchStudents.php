<?php

namespace App\Console\Commands;

use App\Services\BatchStudentPurgeService;
use Illuminate\Console\Command;

class PurgeArchivedBatchStudents extends Command
{
    protected $signature = 'roster:purge-archived';

    protected $description = 'Permanently delete batch_students rows archived 30+ days ago.';

    public function handle(BatchStudentPurgeService $service): int
    {
        $result = $service->purgeExpiredArchives();

        $this->info(
            "Archive purge complete. Purged: {$result['purged']} row(s) archived before {$result['cutoff']}. ".
            "Protected (would re-gate a legacy student): {$result['protected']}."
        );

        return self::SUCCESS;
    }
}
