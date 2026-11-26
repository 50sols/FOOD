<?php

namespace Tests\Unit;

use App\Domain\Updater\UpdatePipeline;
use PHPUnit\Framework\TestCase;

class UpdaterPipelineTest extends TestCase
{
    public function test_files_backup_precedes_maintenance_but_verified_database_snapshot_follows_it(): void
    {
        $filesBackup = array_search('backup_files', UpdatePipeline::STAGES, true);
        $maintenance = array_search('maintenance_mode', UpdatePipeline::STAGES, true);
        $databaseBackup = array_search('backup_database', UpdatePipeline::STAGES, true);
        $extract = array_search('extract_release', UpdatePipeline::STAGES, true);

        $this->assertIsInt($filesBackup);
        $this->assertIsInt($maintenance);
        $this->assertIsInt($databaseBackup);
        $this->assertIsInt($extract);
        // A pre-maintenance DB backup could erase writes committed between
        // its snapshot and the maintenance gate if a later rollback occurs.
        $this->assertLessThan($maintenance, $filesBackup);
        $this->assertLessThan($databaseBackup, $maintenance);
        $this->assertLessThan($extract, $databaseBackup);
    }
}
