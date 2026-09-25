<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Runs the T2 v2 real submit-path Node harness (tests/harness/csrf-recovery.run.mjs).
 * The harness reads the LIVE blade file, executes submit() -> post() -> 419 ->
 * ensureFreshCsrf() -> retry with a mocked fetch, and fails the PHPUnit test if
 * any submit-path assertion breaks (e.g. the 419 handler is removed).
 */
class CsrfRecoveryHarnessRunTest extends TestCase
{
    public function test_real_submit_path_recovery_harness_passes(): void
    {
        $node = null;
        foreach (['node', 'node.exe'] as $candidate) {
            $which = trim((string) @shell_exec('where '.escapeshellarg($candidate)) ?: '');
            if ($which !== '') {
                $node = $candidate;
                break;
            }
        }

        if ($node === null) {
            $this->markTestSkipped('Node.js chưa cài đặt trong môi trường này; bỏ qua harness JS.');
        }

        $script = base_path('tests/harness/csrf-recovery.run.mjs');
        $this->assertTrue(File::exists($script), 'Harness script phải tồn tại');

        $process = new Process([$node, $script], base_path());
        $process->setTimeout(60);
        $process->run();

        $output = $process->getOutput() . $process->getErrorOutput();

        $this->assertTrue($process->isSuccessful(), "Harness phải PASS(exit 0).\n".$output);
        $this->assertStringContainsString('RESULT:', $output);
        $this->assertStringNotContainsString('FAIL', $output);
    }
}