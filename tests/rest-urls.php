<?php
// Both REST address forms must work for query strings (plain permalinks use ?rest_route=).
a_test('T-104', function () {
    exec('node '.escapeshellarg(__DIR__.'/rest-url-probe.js'), $lines, $code);
    eq($code, 0, 'REST URL probe exits cleanly');
    $result = json_decode(implode("\n", $lines), true, 512, JSON_THROW_ON_ERROR);
    check(count($result) === 6, 'six REST URL checks ran');
    foreach ($result as $label => $passed) check($passed === true, $label);
});
