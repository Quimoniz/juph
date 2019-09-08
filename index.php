<?php
/*
 * Steps:
 * 0.9 check password
 * 1a check if database exists
 * 1b if not: create database/tables
 * 1c handle ajax
 * 2a check db for last scan
 * 2b if last db check >= 1hour, then do filesystem/music dir scan
 * 3a load last played playlist (but do not play yet)
 * (3b load list of all playlists)
 * 4 present HTML5-Audio-Player
 *
 * TODO: handle each and every mysql query: log mysql errors to a file
 *
 * MySQL query to get the size of all tables:
 * SELECT `table_name` AS 'tbl', round(((data_length + index_length) / 1024 / 1024), 2) AS 'size in MB' FROM information_schema.TABLES WHERE table_schema='juph';
 *
 * MySQL query to get the number of tagified and untagified files in `filecache`:
 * (SELECT 'tagitfied' AS 'tagified', COUNT(`id`) AS 'anzahl' FROM `filecache` WHERE `tagified`='Y') UNION (SELECT 'untagified' AS 'tagified', COUNT(`id`) AS 'anzahl' FROM `filecache` WHERE `tagified`='N');

 */
$CONFIG_FILE = 'config.php';
$CONFIG_VAR = NULL;
$do_setup = true;
$cur_time = time();
$SESSION_ID = '';

require_once('include/commons.php');

if( file_exists($CONFIG_FILE))
{
    require_once($CONFIG_FILE);
    if(isset($CONFIG_VAR['setup_complete']) && $CONFIG_VAR['setup_complete'])
    {
        $do_setup = false;
    }
}
if( $do_setup)
{
    if(isset($_GET['ajax']))
    {
        server_error("Ajax unavailable", true);
    } else
    { 
        include('include/setup.php');
    }
    exit(0);
}

/* check if necessary configuration variables are available */
if(!isset($CONFIG_VAR['DB_ADDR'])
|| !isset($CONFIG_VAR['DB_PORT']) 
|| !isset($CONFIG_VAR['DB_DB']) 
|| !isset($CONFIG_VAR['DB_USER']) 
|| !isset($CONFIG_VAR['DB_PWD']) 
|| !isset($CONFIG_VAR['ACCESS_PWD'])
|| !isset($CONFIG_VAR['MUSIC_DIR_ROOT']))
{
    server_error('Could not load essential configuration variables, setup correct?');
    //TODO: also provide a link to juph setup
    exit(0);
}

//step 0.9 check password

require_once('include/check_access.php');

/* try to connect to database */
$dbcon = new mysqli($CONFIG_VAR['DB_ADDR'], $CONFIG_VAR['DB_USER'], $CONFIG_VAR['DB_PWD'], $CONFIG_VAR['DB_DB'], (int) $CONFIG_VAR['DB_PORT']);
if($dbcon->connect_errno)
{
    server_error('could not connect to database');
    exit(0);
} 

//maybe TODO for later: bother with incomplete scans (e.g. `completed`='N')
$result_scan_check = @$dbcon->query('SELECT `time`,`completed` FROM `scans` WHERE `completed`=\'Y\' ORDER BY `time` DESC LIMIT 1');
$need_scan = true;
$need_create_table = false;
//step 1a, check for db
if(FALSE === $result_scan_check)
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
//step 1b, table creation
if($need_create_table)
{
    require_once('include/create-tables.php');
}

//step 1c, handle ajax
$AJAX_PAGE_LIMIT = 10;
if(isset($_GET['ajax']))
{
    require_once('include/ajax.php');
}
if(isset($_GET['put_file_info']))
{
    require_once('include/put_file_info.php');
}

//step 2a, lookup scans
//TODO: why do we do this lookup twice? - reduce this to just one query
$need_scan_music_dir = True;
if(!(FALSE === $result_scan_check) && 0 < $result_scan_check->num_rows)
{
    $last_scan_row = $result_scan_check->fetch_assoc();
    $last_scan_time = (int) $last_scan_row['time'];
    $last_scan_completed = $last_scan_row['completed'];
    if($last_scan_time >= ($cur_time - 86400 * 7) && 'Y' == $last_scan_completed)
    {
        $need_scan_music_dir = False;
    }
}

//step 2b, do scan
//DONE: tested this section with detailed output of found files
if($need_scan_music_dir)
{
    scan_music_dir($CONFIG_VAR['MUSIC_DIR_ROOT'], $dbcon);
}


//step 4, present audio player
?>
<!DOCTYPE html5>
<html>
<head>
<meta charset="utf8" />
<title>juph audio player</title>
<script type="text/javascript">
<?php require_once('include/main_javascript.php'); ?>
</script>
<style type="text/css">
<?php require_once('include/main_css.css'); ?>
</style>
</head>
<body>
<?php require_once('include/main_body.php'); ?>

</body>
</html>
