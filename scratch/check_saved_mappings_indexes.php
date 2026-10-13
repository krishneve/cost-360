<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/upload_centre.php';

$st = dbQuery("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'saved_mappings'");
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    echo $r['indexname'] . " => " . $r['indexdef'] . "\n";
}
