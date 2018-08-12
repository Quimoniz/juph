<?php
$CONFIG_FILE = 'config.ini';
$CONFIG_VAR = NULL;
$do_setup = true;


function server_error($message = "Internal Server Error")
{
    header('HTTP/1.1 500 Server error');
    echo "<!DOCTYPE html>\n<html>\n<head>\n<title>Server Error</title>\n</head>\n";
    echo "<h1>Internal Server Error</h1>\n";
    echo "<div>" . htmlspecialchars($message) . "</div>\n";
    echo "<body>\n</body>\n</html>";
}


if( file_exists($CONFIG_FILE))
{
    $CONFIG_VAR = parse_ini_file($CONFIG_FILE);
    if(isset($CONFIG_VAR['setup_complete']) && $CONFIG_VAR['setup_complete'])
    {
        $do_setup = false;
    }
}
if( $do_setup)
{
    include('setup.php');
    exit(0);
}

/* check if necessary configuration variables are available */
if(!isset($CONFIG_VAR['DB_ADDR'])
|| !isset($CONFIG_VAR['DB_PORT']) 
|| !isset($CONFIG_VAR['DB_DB']) 
|| !isset($CONFIG_VAR['DB_USER']) 
|| !isset($CONFIG_VAR['DB_PWD']) 
|| !isset($CONFIG_VAR['MUSIC_DIR_ROOT']))
{
    server_error('Could not load essential configuration variables, setup correct?');
    //TODO: also provide a link to juph setup
    exit(0);
}

/* try to connect to database */
$dbcon = new mysqli($CONFIG_VAR['DB_ADDR'], $CONFIG_VAR['DB_USER'], $CONFIG_VAR['DB_PWD'], $CONFIG_VAR['DB_DB'], (int) $CONFIG_VAR['DB_PORT']);
if($dbcon->connect_errno)
{
    server_error('could not connect to database');
    exit(0);
} 

//maybe TODO for later: bother with incomplete scans (e.g. `completed`='N')
$result = @$dbcon->query('SELECT `time` FROM `scans` ORDER BY `time` DESC LIMIT 1');
$need_scan = true;
$need_create_table = false;
if(FALSE === $result)
{
    if(1146 == $dbcon->errno)
    {
        $need_create_table = true;
    } else
    {
        server_error('Error while looking up database table `scans`');
        $dbcon->close();
        exit(0);
    }
}

if($need_create_table)
{
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
    $create_sql .= '`id` BIGINT NOT NULL AUTO_INCREMENT UNIQUE,';
    $create_sql .= '`path` VARCHAR(1024) CHARACTER SET utf8 NOT NULL,';
    $create_sql .= '`last_scan` BIGINT NOT NULL,';
    $create_sql .= '`size` BIGINT NOT NULL,';
    $create_sql .= '`valid` ENUM(\'Y\',\'N\') NOT NULL,';
    // maybe TODO for later: more fields e.g. hash, len
    $create_sql .= 'KEY(`id`)';
    $create_sql .= ') ENGINE=InnoDB; ';
    // TABLE `playlists`
    $create_sql .= 'CREATE TABLE IF NOT EXISTS `playlists` (';
    $create_sql .= '`id` BIGINT NOT NULL AUTO_INCREMENT UNIQUE,';
    $create_sql .= '`name` VARCHAR(256) NOT NULL,';
    $create_sql .= '`description` TEXT CHARACTER SET utf8 NULL,';
    $create_sql .= '`thumb_path` VARCHAR(1024) CHARACTER SET utf8 NULL,';
    $create_sql .= '`count_played` BIGINT NOT NULL,';
    $create_sql .= 'KEY(`id`)';
    $create_sql .= ') ENGINE=InnoDB; ';
    // TABLE `relation_playlists`
    $create_sql .= 'CREATE TABLE IF NOT EXISTS `relation_playlists` (';
    $create_sql .= '`fid` BIGINT NULL,';
    $create_sql .= '`pid` BIGINT NOT NULL,';
    $create_sql .= '`prank` BIGINT NOT NULL,';
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
    $create_sql .= '`tagname` VARCHAR(256) CHARACTER SET utf8 NOT NULL,';
    $create_sql .= '`description` TEXT CHARACTER SET utf8 NULL,';
    $create_sql .= 'KEY(`id`)';
    $create_sql .= ') ENGINE=InnoDB; ';
    // TABLE `relation_tags`
    $create_sql .= 'CREATE TABLE IF NOT EXISTS `relation_tags` (';
    $create_sql .= '`fid` BIGINT NOT NULL,';
    $create_sql .= '`tid` BIGINT NOT NULL,';
    $create_sql .= 'KEY(`fid`, `tid`),';
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
            echo 'Error creating database (' . $dbcon->errno . '): ' . htmlspecialchars($dbcon->error) . '<br/>';
        }
    }
}
echo 'all is well that ends well';
?>
