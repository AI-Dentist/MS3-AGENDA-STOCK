<?php

/** One bounded, synthetic HTTP diagnostic. Never runs the full test file. */
$seconds = isset($argv[1]) ? filter_var($argv[1], FILTER_VALIDATE_INT) : 20;
if ($seconds === false || $seconds < 1 || $seconds > 120) {
    fwrite(STDERR, "Diagnostic cap must be between 1 and 120 seconds.\n");
    exit(2);
}
$root = dirname(__DIR__, 2);
$evidence = sys_get_temp_dir().'/ms3-stock-diagnostic-'.bin2hex(random_bytes(8));
mkdir($evidence, 0700);
$command = [PHP_BINARY, $root.'/vendor/bin/phpunit',
    'tests/Feature/StockMovementIdempotencyTest.php', '--filter',
    'test_replay_returns_original_response_and_verified_actor_once', '--debug', '--colors=never'];
fwrite(STDOUT, gmdate('c').' diagnostic start; evidence: '.$evidence.PHP_EOL);
fflush(STDOUT);
$previous = getenv('STOCK_MOVEMENT_DIAGNOSTIC');
putenv('STOCK_MOVEMENT_DIAGNOSTIC=1');
$process = proc_open($command, [
    0 => ['pipe', 'r'], 1 => ['file', $evidence.'/stdout.log', 'w'],
    2 => ['file', $evidence.'/stderr.log', 'w'],
], $pipes, $root, null, ['bypass_shell' => true]);
putenv($previous === false ? 'STOCK_MOVEMENT_DIAGNOSTIC' : 'STOCK_MOVEMENT_DIAGNOSTIC='.$previous);
if (! is_resource($process)) {
    fwrite(STDERR, 'Could not launch diagnostic child.'.PHP_EOL);
    exit(2);
}
fclose($pipes[0]);
$deadline = microtime(true) + $seconds;
$timedOut = false;
do {
    $status = proc_get_status($process);
    if (! $status['running']) {
        break;
    }
    if (microtime(true) >= $deadline) {
        $timedOut = true;
        proc_terminate($process);
        break;
    }
    usleep(50000);
} while (true);
$exit = $timedOut ? 124 : $status['exitcode'];
proc_close($process);
fwrite(STDOUT, gmdate('c').' diagnostic end; child exit='.$exit.'; timed_out='.($timedOut ? 'yes' : 'no').PHP_EOL);
fwrite(STDOUT, "--- captured stdout ---\n".file_get_contents($evidence.'/stdout.log'));
fwrite(STDERR, "--- captured stderr ---\n".file_get_contents($evidence.'/stderr.log'));
file_put_contents($evidence.'/result.json', json_encode([
    'command' => $command, 'exit' => $exit, 'timed_out' => $timedOut,
], JSON_PRETTY_PRINT));
// Raw synthetic evidence is retained for the parent, not committed or uploaded.
exit($exit);
