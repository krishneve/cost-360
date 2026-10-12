<?php
require_once __DIR__ . '/../config/auth.php';
$uids = [1, 2, 3, 4, 6, 7, 8, 70, 140, 141, 142];
foreach ($uids as $uid) {
    echo "UID $uid: UPLOAD_CENTRE=" . (userHasMenuAccess('UPLOAD_CENTRE', $uid)?'T':'F') . " | MAPPING_CENTRE=" . (userHasMenuAccess('MAPPING_CENTRE', $uid)?'T':'F') . " | IMPORT_HISTORY=" . (userHasMenuAccess('IMPORT_HISTORY', $uid)?'T':'F') . " | AUDIT_LOGS=" . (userHasMenuAccess('AUDIT_LOGS', $uid)?'T':'F') . "\n";
}
