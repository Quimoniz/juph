<?php
$create_sql = '';
// TABLE `scans`
$create_sql .= 'CREATE TABLE IF NOT EXISTS `scans` (';
$create_sql .= '`time` BIGINT NOT NULL,';
$create_sql .= '`completed` ENUM(\'Y\',\'N\') NOT NULL,';
$create_sql .= '`error_message` TEXT CHARACTER SET utf8 NULL,';
$create_sql .= '`files_scanned` BIGINT NULL,';
$create_sql .= 'KEY(`time`)';
$create_sql .= ') ENGINE=InnoDB; ';
// TABLE `filecache`
$create_sql .= 'CREATE TABLE IF NOT EXISTS `filecache` (';
$create_sql .= '`id` BIGINT NOT NULL AUTO_INCREMENT,';
$create_sql .= '`path_hash` CHAR(32) CHARACTER SET latin1 NOT NULL DEFAULT \'\' UNIQUE,';
$create_sql .= '`path_str` VARCHAR(1024) CHARACTER SET utf8 NOT NULL,';
$create_sql .= '`path_filename` VARCHAR(128) CHARACTER SET utf8 NOT NULL DEFAULT \'\',';
$create_sql .= '`last_scan` BIGINT NOT NULL,';
$create_sql .= '`size` BIGINT NOT NULL,';
$create_sql .= '`valid` ENUM(\'Y\',\'N\') NOT NULL DEFAULT \'Y\',';
$create_sql .= '`count_played` BIGINT NOT NULL DEFAULT 0';
$create_sql .= '`tagified` ENUM(\'Y\',\'N\') NOT NULL DEFAULT \'N\',';
// maybe TODO for later: more fields e.g. hash, len
$create_sql .= 'KEY(`id`),';
$create_sql .= 'INDEX(`path_hash`),';
$create_sql .= 'INDEX(`path_filename`)';
$create_sql .= ') ENGINE=InnoDB; ';
// TABLE `splaylist`
$create_sql .= 'CREATE TABLE IF NOT EXISTS `session_playlist` (';
$create_sql .= '`session_id` CHAR(32) CHARACTER SET latin1 NOT NULL,';
$create_sql .= '`fid` BIGINT NOT NULL,';
$create_sql .= '`prank` BIGINT NOT NULL,';
$create_sql .= '`options` VARCHAR(1024) CHARACTER SET utf8 NULL DEFAULT \'{}\'';
$create_sql .= 'KEY(`session_id`, `fid`, `prank`)';
$create_sql .= ') ENGINE=InnoDB; ';
// TABLE `playlists`
$create_sql .= 'CREATE TABLE IF NOT EXISTS `playlists` (';
$create_sql .= '`id` BIGINT NOT NULL AUTO_INCREMENT UNIQUE,';
$create_sql .= '`name` VARCHAR(256) NOT NULL,';
$create_sql .= '`description` TEXT CHARACTER SET utf8 NULL,';
$create_sql .= '`thumb_path` VARCHAR(1024) CHARACTER SET utf8 NULL,';
$create_sql .= '`count_played` BIGINT NOT NULL DEFAULT 0,';
$create_sql .= 'KEY(`id`)';
$create_sql .= ') ENGINE=InnoDB; ';
// TABLE `relation_playlists`
$create_sql .= 'CREATE TABLE IF NOT EXISTS `relation_playlists` (';
$create_sql .= '`fid` BIGINT NULL,';
$create_sql .= '`pid` BIGINT NOT NULL,';
$create_sql .= '`prank` BIGINT NOT NULL,';
$create_sql .= '`options` VARCHAR(1024) CHARACTER SET utf8 NULL DEFAULT \'{}\'';
$create_sql .= 'KEY(`fid`,`pid`,`prank`),';
$create_sql .= 'FOREIGN KEY fk_pfile(`fid`) ';
$create_sql .= 'REFERENCES `filecache`(`id`) ';
$create_sql .= 'ON DELETE SET NULL ';
$create_sql .= 'ON UPDATE CASCADE,';
$create_sql .= 'FOREIGN KEY fk_playlist(`pid`) ';
$create_sql .= 'REFERENCES `playlists`(`id`) ';
$create_sql .= 'ON DELETE CASCADE ';
$create_sql .= 'ON UPDATE CASCADE';
$create_sql .= ') ENGINE=InnoDB; ';
// TABLE `tags`
$create_sql .= 'CREATE TABLE IF NOT EXISTS `tags` (';
$create_sql .= '`id` BIGINT NOT NULL AUTO_INCREMENT UNIQUE,';
$create_sql .= '`tagname` VARCHAR(128) CHARACTER SET utf8 NOT NULL,';
$create_sql .= '`description` TEXT CHARACTER SET utf8 NULL,';
$create_sql .= 'UNIQUE KEY(`id`),';
$create_sql .= 'UNIQUE KEY(`tagname`)';
$create_sql .= ') ENGINE=InnoDB; ';
// TABLE `relation_tags`
$create_sql .= 'CREATE TABLE IF NOT EXISTS `relation_tags` (';
$create_sql .= '`fid` BIGINT NOT NULL,';
$create_sql .= '`tid` BIGINT NOT NULL,';
$create_sql .= 'CONSTRAINT \'tfindex\' UNIQUE KEY(`fid`, `tid`),';
$create_sql .= 'FOREIGN KEY fk_tfile(`fid`) ';
$create_sql .= 'REFERENCES `filecache`(`id`) ';
$create_sql .= 'ON DELETE CASCADE ';
$create_sql .= 'ON UPDATE CASCADE, ';
$create_sql .= 'FOREIGN KEY fk_tag(`tid`) ';
$create_sql .= 'REFERENCES `tags`(`id`) ';
$create_sql .= 'ON DELETE CASCADE ';
$create_sql .= 'ON UPDATE CASCADE';
$create_sql .= ') ENGINE=InnoDB';
foreach (explode(';', $create_sql) as $table_sql)
{
    $query_result = @$dbcon->query($table_sql);
    if(FALSE === $query_result)
    {
        server_error('Error during creation of tables (errno: ' . $dbcon->errno . '): ' . htmlspecialchars($dbcon->error));
        exit(0);
    }
}
?>
