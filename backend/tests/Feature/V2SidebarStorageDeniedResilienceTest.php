<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class V2SidebarStorageDeniedResilienceTest extends TestCase
{
    public function test_denied_browser_storage_does_not_break_sidebar_navigation_controls(): void
    {
        // Execute the actual inline Blade JavaScript in an isolated Node VM.
        // A throwing Storage getter previously aborted initialization before
        // the mobile launcher, Escape handler, search and desktop toggle existed.
        $process = new Process([
            'node',
            base_path('tests/Fixtures/sidebar-storage-denied.cjs'),
        ], base_path());
        $process->setTimeout(30);
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            "Sidebar browser-storage runtime regression failed:\n"
            .$process->getOutput()."\n".$process->getErrorOutput(),
        );
    }
}
