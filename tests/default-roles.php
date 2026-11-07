<?php
// DDL runs in a child process so it cannot commit the suite transaction/savepoints.
a_test('T-103', function () {
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/default-roles-probe.php'), $lines, $code);
    eq($code, 0, 'isolated activation probe exits cleanly');
    $result = json_decode(implode("\n", $lines), true, 512, JSON_THROW_ON_ERROR);
    foreach ($result as $label => $passed) check($passed === true, $label);
});
