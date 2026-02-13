<?php
// Simple backup script
$backup_file = 'backups/db_backup_' . date("Y-m-d_H-i-s") . '.sql';
$command = "C:\\xampp\\mysql\\bin\\mysqldump --user=root --password= phonestore_db > " . $backup_file;
system($command, $output);

if ($output === 0) {
    echo "Backup created successfully: " . $backup_file;
} else {
    echo "Backup failed";
}
?>