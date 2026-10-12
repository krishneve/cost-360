<?php
$content = file_get_contents('database/schema.sql');
$content = str_replace(
    "    action          VARCHAR(20) NOT NULL CHECK (action IN ('INSERT','UPDATE','DELETE')),",
    "    action          VARCHAR(50) NOT NULL,",
    $content
);
file_put_contents('database/schema.sql', $content);
echo "Updated schema.sql successfully.\n";
