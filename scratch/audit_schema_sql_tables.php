<?php
$content = file_get_contents('database/schema.sql');
preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?([a-zA-Z0-9_]+)/i', $content, $matches);
echo "Tables in database/schema.sql:\n";
print_r($matches[1]);
