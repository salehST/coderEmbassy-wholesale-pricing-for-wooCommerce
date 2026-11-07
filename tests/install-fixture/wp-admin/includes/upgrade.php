<?php
// Deliberately no SQL: exercise the real installer control flow in a child process.
function dbDelta($sql) {
    global $schema_calls,$scenario,$wpdb;
    $schema_calls++;
    if ($scenario==='failure') { $wpdb->last_error='simulated schema failure'; return []; }
    return ['fixture schema applied'];
}
