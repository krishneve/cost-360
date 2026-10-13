<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/upload_centre.php';

$st = dbQuery("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'saved_mappings' ORDER BY ordinal_position");
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    echo $r['column_name'] . ' (' . $r['data_type'] . ")\n";
}
