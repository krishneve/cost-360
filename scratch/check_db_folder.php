<?php
if (file_exists('database/sample_customer.sql')) {
    echo file_get_contents('database/sample_customer.sql');
} else {
    echo "sample_customer.sql does not exist. Listing database/ directory:\n";
    print_r(scandir('database'));
}
