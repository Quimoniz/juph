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
 * 3b load list of all playlists
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

function gen_pwd($pwd_len = 15, $use_special_chars = true)
{
    $chr_grp = array(array(48,57),array(65,91),array(97,123));
    $chr_used_grp = array(0,0,0);
    if($use_special_chars)
    {
        $chr_grp[] = array(35,48);
        $chr_grp[] = array(58,63);
        $chr_grp[] = array(91,97);
        $chr_used_grp[] = 0;
        $chr_used_grp[] = 0;
        $chr_used_grp[] = 0;
    }
    $out_pwd = '';
    for($i = 0; $i < $pwd_len; $i++)
    {
        $which_grp = rand(0, count($chr_grp) - 1);
        if($i >= $pwd_len)
        {
            for($j = 0; $j < 5; $j++)
            {
                if(0 == $chr_used_grp)
                {
                    $which_grp = $j;
                    break;
                }
            }
        }
        $chr_used_grp[$which_grp]++;
        $out_pwd = $out_pwd . chr(rand($chr_grp[$which_grp][0], $chr_grp[$which_grp][1]));
    }
    return $out_pwd;
}

function prepare_premature_disconnect()
{
    header("Connection: close");
    header("Content-Encoding: none");
    ob_end_clean();
    ignore_user_abort(true);
    ob_start();
}
function do_premature_disconnect()
{
    $content_length = ob_get_length();
    header("Content-Length: " . $content_length);
    ob_end_flush();
    flush();
}

function tagify_filecache($dbcon)
{
    if(!$dbcon)
    {
        return;
    }
    $untagified_result = $dbcon->query('SELECT `id`,`path_str` FROM `filecache` WHERE `tagified`=\'N\' ORDER BY `id` ASC LIMIT 100');
    $ignore_levels = 1;
    if(!(FALSE === $untagified_result) && 0 < $untagified_result->num_rows)
    {
        $cur_row = false;
        $cur_dir_arr = false;
        $cur_dir_name = "";
        $cur_id = -1;
        // do two rounds,
        // first round, gather all the different tags
        //   -> enter them into table `tags`
        // second round, compile an insert for file<->tag relation
        $arr_tags = array();
        while($cur_row = $untagified_result->fetch_assoc())
        {
            $cur_id = $cur_row['id'];
            $cur_dir_name = dirname($cur_row['path_str']);
            $cur_dir_arr = explode('/', $cur_dir_name);
            array_splice($cur_dir_arr, 0, $ignore_levels);
            for($i = 0; $i < count($cur_dir_arr); $i++)
            {
               $arr_tags[$cur_dir_arr[$i]] = -1;
            }
        }
        $insert_sql = 'INSERT IGNORE INTO `tags` (`tagname`, `description`) VALUES';
        $select_sql = 'SELECT `id`, `tagname` FROM `tags` WHERE ';
        $i = 0;
        foreach($arr_tags as $cur_key => $cur_value)
        {
            if(0 < $i)
            {
                $insert_sql .= ', ';
                $select_sql .= ' OR ';
            }
            $insert_sql .= '(\'' . $dbcon->real_escape_string($cur_key) . '\', \'from folder ' . $dbcon->real_escape_string($cur_key) . '\')';
            $select_sql .= '`tagname`=\'' . $dbcon->real_escape_string($cur_key) . '\'';
            $i++;
        }
        if(0 < $i)
        {
            $dbcon->query($insert_sql);
            $tagids_result = $dbcon->query($select_sql);
            if(!(FALSE === $tagids_result))
            {
                while($cur_row = $tagids_result->fetch_assoc())
                {
                    if(isset($arr_tags[$cur_row['tagname']]))
                    {
                        $arr_tags[$cur_row['tagname']] = (int) $cur_row['id'];
                    }
                }
            }
            $untagified_result->data_seek(0);
           
            $insert_sql = 'INSERT IGNORE INTO `relation_tags` (`fid`,`tid`) VALUES';
            $update_sql = 'UPDATE `filecache` SET `tagified`=\'Y\' WHERE ';
            $j = 0;
            $k = 0;
            while($cur_row = $untagified_result->fetch_assoc())
            {
                $cur_id = $cur_row['id'];
                $cur_dir_name = dirname($cur_row['path_str']);
                $cur_dir_arr = explode('/', $cur_dir_name);
                array_splice($cur_dir_arr, 0, $ignore_levels);
                for($i = 0; $i < count($cur_dir_arr); $i++)
                {
                   if(-1 < $arr_tags[$cur_dir_arr[$i]])
                   {
                       if(0 < $j)
                       {
                           $insert_sql .= ', ';
                       }
                       $insert_sql .= '(' . $cur_id . ', ' . $arr_tags[$cur_dir_arr[$i]] . ')';
                       $j++;
                   }
                }
                if(0 < $k)
                {
                    $update_sql .= ' OR ';
                }
                $update_sql .= '`id`=' . $cur_id;
                $k++;
            }
            $dbcon->query($insert_sql);
            $dbcon->query($update_sql);
        }
    }
}

class MinimalisticFile {
    public $path = '';
    public $fullpath = '';
    public $name = '';
    public $size = 0;
    public function __construct($basedir, $filename)
    {
        global $CONFIG_VAR;
        $this->fullpath = $basedir . $filename; 
        if(isset($CONFIG_VAR['MUSIC_DIR_ROOT']))
        {
            $this->path = substr($this->fullpath, strlen($CONFIG_VAR['MUSIC_DIR_ROOT']) + 1);
        }
        $this->name = $filename;
        $this->size = filesize($this->fullpath);
    }
}
function js_escape($source_str)
{
    return str_replace(array("\\", "\"","\n"), array("\\\\", "\\\"", "\\n"), $source_str);
}
function server_error($message = "Internal Server Error", $ajax = false)
{
    header('HTTP/1.1 500 Server error');
    if(!$ajax)
    {
        echo "<!DOCTYPE html>\n<html>\n<head>\n<title>Server Error</title>\n</head>\n";
        echo "<h1>Internal Server Error</h1>\n";
        echo "<div>" . htmlspecialchars($message) . "</div>\n";
        echo "<body>\n</body>\n</html>";
    } else
    {
        echo "{\n\"success\": false,\n\"error_message\":\"" . js_escape($message) . "\"\n}";
    }
}
function client_error($message = "Client side error", $ajax = false)
{
    header('HTTP/1.1 400 Client error');
    if(!$ajax)
    {
        echo "<!DOCTYPE html>\n<html>\n<head>\n<title>Client error</title>\n</head>\n";
        echo "<h1>Bad Request</h1>\n";
        echo "<div>" . htmlspecialchars($message) . "</div>\n";
        echo "<body>\n</body>\n</html>";
    } else
    {
        echo "{\n\"success\": false,\n\"error_message\":\"" . js_escape($message) . "\"\n}";
    }
}
function flush_files_to_db($dbcon, $files_to_insert)
{
    global $cur_time;
    if(!$dbcon || !is_array($files_to_insert))
    {
        return;
    }
    // combine sql queries, don't do a separate query for every single entry
    $sql_values = 'VALUES ';
    $i = 0;
    foreach($files_to_insert as $cur_file)
    {
        $hashed_filename = md5($cur_file->path);
        $escaped_path = $dbcon->real_escape_string($cur_file->path);
        $escaped_filename = $dbcon->real_escape_string(basename($cur_file->path));
        if(0 < $i)
        {
            $sql_values .= ', ';
        }
        $sql_values .= '(NULL, \'' . $hashed_filename . '\', \'' . $escaped_path . '\', \'' . $escaped_filename . '\', ' . $cur_time . ', ' . $cur_file->size . ', \'Y\')';
        $i++;
    }
    @$dbcon->query('INSERT INTO `filecache` (`id`, `path_hash`, `path_str`, `path_filename`, `last_scan`, `size`, `valid`) ' . $sql_values . ' ON DUPLICATE KEY UPDATE `last_scan` = VALUES(`filecache`.`last_scan`), `valid`=\'Y\'');
}


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
        include('setup.php');
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
$access_granted = false;
$access_setcookie = false;
if(isset($_GET['access_pwd']) && 0 === strcmp($CONFIG_VAR['ACCESS_PWD'], $_GET['access_pwd']) && isset($_GET['access_allow_cookies']))
{
    $access_granted = true;
    $access_setcookie = true;
}
if(isset($_POST['access_pwd']) && 0 === strcmp($CONFIG_VAR['ACCESS_PWD'], $_POST['access_pwd']) && isset($_POST['access_allow_cookies']))
{
    $access_granted = true;
    $access_setcookie = true;
}
if(isset($_COOKIE['access_pwd']) && 0 === strcmp($CONFIG_VAR['ACCESS_PWD'], $_COOKIE['access_pwd']))
{
    $access_granted = true;
}
if($access_granted)
{
    if($access_setcookie)
    {
        setcookie('access_pwd', $CONFIG_VAR['ACCESS_PWD'], time() + 86400 * 7);
    }
    if(isset($_COOKIE['session_id']))
    {
        $SESSION_ID = $_COOKIE['session_id'];
    } else
    {
        $SESSION_ID = gen_pwd(25, false);
    }
    setcookie('session_id', $SESSION_ID, time() + 86400 * 30);
} else
{
?>
<!DOCTYPE html5>
<html>
<head>
<meta charset="utf8" />
<title>Password Prompt</title>
<style type="text/css">
.pw_wrapper {
  width: 35em;
  margin: 100px auto 0px auto;
}
</style>
</head>
<body>
<div class="pw_wrapper">
<fieldset>
<legend>Password:</legend>
<form method="POST" action="" onsubmit="return document.getElementById('access_allow_cookies').checked;">
<input type="checkbox" name="access_allow_cookies" id="access_allow_cookies" onchange="document.getElementById('form_submit').disabled=!this.checked; return true;"/>
<label for="access_allow_cookies">Allow cookies</label><br/>
<input type="password" name="access_pwd" size="30" />
<input type="submit" id="form_submit" disabled="disabled" />
<script type="text/javascript">document.getElementById("access_allow_cookies").checked=false;</script>
</form>
</fieldset>
</div>
</body>
</html>
<?php
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
//step 1a, check for db
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
//step 1b, table creation
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
            server_error('Error during creation of tables (errno: ' . $dbcon->errno . '): ' . htmlspecialchars($dbcon->error));
            exit(0);
        }
    }
}

//step 1c, handle ajax
$AJAX_PAGE_LIMIT = 10;
if(isset($_GET['ajax']))
{
    prepare_premature_disconnect();
    if(isset($_GET['matching_tracks']))
    {
        $search_subject = $_GET['matching_tracks'];
        if(3 > strlen($search_subject))
        {
            client_error("too few characters to search", true);
        
        } else
        {
            $search_offset = 0;
            if(isset($_GET['matching_offset']))
            {
                $search_offset = (int) $_GET['matching_offset'];
                if(0 > $search_offset)
                {
                    $search_offset = 0;
                }
            }
            $search_subject = $dbcon->real_escape_string($search_subject);
            $count_matches = 0;

            $result_matches = @$dbcon->query('SELECT COUNT(`filecache`.`id`) AS \'count_matches\' FROM `filecache` WHERE `filecache`.`valid`=\'Y\' AND (`filecache`.`path_filename` LIKE \'%' . $search_subject . '%\' OR `filecache`.`id`=ANY(SELECT DISTINCT `relation_tags`.`fid` FROM `relation_tags` WHERE `relation_tags`.`tid`= ANY(SELECT `tags`.`id` FROM `tags` WHERE `tagname` LIKE \'%' . $search_subject . '%\'))) UNION SELECT COUNT(`id`) AS \'count_matches\' FROM `playlists` WHERE `name` LIKE \'%' . $search_subject . '%\'');
            if(!(FALSE === $result_matches))
            {
                while($cur_row = $result_matches->fetch_assoc())
                {
                    $count_matches += (int) $cur_row['count_matches'];
                }


                if(0 < $count_matches)
                {

                    $result_matches = @$dbcon->query('(SELECT `id`,`path_str`, \'file\' AS \'type\',`count_played` AS \'count_played\' FROM `filecache` WHERE `valid`=\'Y\' AND (`filecache`.`path_filename` LIKE \'%' . $search_subject . '%\' OR `filecache`.`id`=ANY(SELECT DISTINCT `relation_tags`.`fid` FROM `relation_tags` WHERE `relation_tags`.`tid`= ANY(SELECT `tags`.`id` FROM `tags` WHERE `tagname` LIKE \'%' . $search_subject . '%\')))) UNION (SELECT `id`, `name`, \'playlist\' AS \'type\', `count_played` AS \'count_played\' FROM `playlists` WHERE `name` LIKE \'%' . $search_subject . '%\') ORDER BY `type` DESC, `count_played` DESC, `path_str` ASC LIMIT ' . $search_offset . ',' . $AJAX_PAGE_LIMIT);
                    if(!(FALSE === $result_matches))
                    {
                        $matches_arr = array();
                        $select_sql = 'SELECT `relation_tags`.`fid` AS \'id\', GROUP_CONCAT(`tags`.`tagname`) AS \'tags\' FROM `relation_tags` INNER JOIN `tags` ON `relation_tags`.`tid`=`tags`.`id` WHERE ';
                        // 'GROUP BY `relation_tags`.`fid`';
                        $i = 0;
                        while($cur_row = $result_matches->fetch_assoc())
                        {
                            $matches_item = array();
                            $matches_item['id'] = (int) $cur_row['id'];
                            $matches_item['type'] = $cur_row['type'];
                            $matches_item['countPlayed'] = (int) $cur_row['count_played'];
                            $matches_item['name'] = $cur_row['path_str'];
                            $matches_item['tags'] = '';
                            $matches_arr[$matches_item['id']] = $matches_item;
                            if('file' == $matches_item['type'])
                            {
                                if(0 < $i)
                                {
                                    $select_sql .= ' OR ';
                                }
                                $select_sql .= '`relation_tags`.`fid`=' . $matches_item['id'];
                                $i++;
                            }
                        }
                        $select_sql .= ' GROUP BY `relation_tags`.`fid`';
                        $tags_result = $dbcon->query($select_sql);
                        while($cur_row = $tags_result->fetch_assoc())
                        {
                            $cur_id = (int) $cur_row['id'];
                            if(isset($matches_arr[$cur_id]))
                            {
                                $matches_arr[$cur_id]['tags'] = $cur_row['tags'];
                            }
                        }
                        echo "{\n\"success\": true,\n\"countMatches\":";
                        echo $count_matches . ",\n\"pageLimit\":" . $AJAX_PAGE_LIMIT;
                        echo ",\n\"offsetMatches\":" . $search_offset . ",\n\"matches\": [\n";
                        $i = 0;
                        foreach($matches_arr as $cur_key => $cur_arr)
                        {
                            if(0 < $i) echo ",\n";
                            echo "{ \"id\": " . $cur_key . ", \"type\": \"" . $cur_arr['type'];
                            echo "\",\"countPlayed\": " . $cur_arr['countPlayed'];
                            echo ",\"name\": \"" . js_escape($cur_arr['name']) . "\"";
                            echo ",\"tags\": \"" . js_escape($cur_arr['tags']) . "\"}";
                            $i++;
                        }
                        echo "]\n}";
                    } else
                    {
                        server_error('database query failure', true);
                    }
                } else
                {
                    server_error('database query failure', true);
                }
            } else
            {
                client_error('no query results', true);
            }
        }
    } else if(isset($_GET['request_track']))
    {
        $target_id = (int) $_GET['request_track'];
        if(0 < $target_id && 9223372036854776000 > $target_id)
        {
            $result_raw = @$dbcon->query('SELECT `path_str`, `valid` FROM `filecache` WHERE `id`=' . $target_id);
            if(!(FALSE === $result_raw) && 0 < $result_raw->num_rows)
            {
                $result_row = $result_raw->fetch_assoc();
                $result_path = $CONFIG_VAR['MUSIC_DIR_ROOT'] . '/' . $result_row['path_str'];
                if('Y' == $result_row['valid'] && file_exists($result_path))
                {
                    @$dbcon->query('UPDATE `filecache` SET `count_played`=`count_played`+1 WHERE `filecache`.`id`=' . $target_id);
                    $target_data = file_get_contents($result_path);
                    header('Content-Type: audio/mp3');
                    echo $target_data;
                }
            }
        }
    } else if(isset($_GET['request_playlist']))
    {
        $playlist_id = (int) $_GET['request_playlist'];
        $result = @$dbcon->query('SELECT `filecache`.`id` AS \'id\', \'file\' AS \'type\', `filecache`.`path_str` AS \'name\' FROM `playlists` INNER JOIN `relation_playlists` ON `playlists`.`id`=`relation_playlists`.`pid` INNER JOIN `filecache` ON `relation_playlists`.`fid`=`filecache`.`id` WHERE `playlists`.`id`=' . $playlist_id . ' ORDER BY `relation_playlists`.`prank` ASC');
        if(!(FALSE === $result))
        {
          echo "{\n\"success\": true,\n\"matches\": [";
          for($i = 0; $cur_row = $result->fetch_assoc(); $i++)
          {
            if(0 < $i) echo ",\n";
            echo "{ \"id\": " . $cur_row['id'] . ", \"type\": \"" . $cur_row['type'] . "\",\"name\": \"" . js_escape($cur_row['name']) . "\"}";
          }
          echo "]\n}";
          @$dbcon->query('UPDATE `playlists` SET `count_played`=`count_played`+1 WHERE `playlists`.`id`=' . $playlist_id);
        } else {
          server_error("Error when looking up playlist", false);
        }
    } else if(isset($_GET['put_playlist']))
    {
        $playlist_name = '';
        $playlist_tracks = '';
        $playlist_description = '';
        if(isset($_POST['playlist_name'])) $playlist_name = $_POST['playlist_name'];
        if(isset($_POST['playlist_tracks'])) $playlist_tracks = $_POST['playlist_tracks'];
        if(isset($_POST['playlist_description'])) $playlist_description = $_POST['playlist_description'];
        if(0 < strlen($playlist_name) && 0 < strlen($playlist_tracks))
        {
            $playlist_name = $dbcon->real_escape_string($playlist_name);
            $str_tracks = explode(',', $playlist_tracks);
            $int_tracks = array();
            foreach($str_tracks as $curValue)
            {
                $curInt = 0;
                $curInt = (int) $curValue;
                if(0 < $curInt && 9223372036854776000 > $curInt)
                {
                    $int_tracks[] = $curInt;
                }
            }
            $playlist_db_id = -1;
            $result = $dbcon->query('SELECT `id` FROM `playlists` WHERE `name`=\'' . $playlist_name . '\'');
            if(!(FALSE === $result) && 0 < $result->num_rows)
            {
                $cur_row = $result->fetch_assoc();
                $playlist_db_id = $cur_row['id'];
            }
            if(-1 < $playlist_db_id)
            {
                $dbcon->query('DELETE FROM `relation_playlists` WHERE `pid`=' . $playlist_db_id);
            } else
            {
                $dbcon->query('INSERT INTO `playlists` (`id`,`name`,`description`) VALUES (NULL, \'' . $playlist_name . '\', \'' . $playlist_description . '\')');
                $playlist_db_id = $dbcon->insert_id;
            }
            $insert_sql = 'INSERT INTO `relation_playlists` (`pid`,`fid`,`prank`) VALUES';
            $i = 1;
            foreach($int_tracks as $curFid)
            {
                if(1 < $i) $insert_sql .= ', ';
                $insert_sql .= '(' . $playlist_db_id . ', ' . $curFid . ', ' . $i . ')';
                $i++;
            }
            $result = $dbcon->query($insert_sql);
            if(FALSE === $result)
            {
                server_error("Could not enter playlist items into playlist", true);
            } else
            {
                echo "{ \"success\": true }";
            }
        }
    } else if(isset($_GET['popular']))
    {
        server_error("Not yet implemented.", true);
    }

    do_premature_disconnect();
    tagify_filecache($dbcon);
    exit(0);
}

//step 2a, lookup scans
//TODO: why do we do this lookup twice? - reduce this to just one query
$result = @$dbcon->query('SELECT `time`,`completed` FROM `scans` WHERE `completed`=\'Y\' ORDER BY `time` DESC LIMIT 1');
$need_scan_music_dir = True;
if(!(FALSE === $result) && 0 < $result->num_rows)
{
    $last_scan_row = $result->fetch_assoc();
    $last_scan_time = (int) $last_scan_row['time'];
    $last_scan_completed = $last_scan_row['completed'];
    if($last_scan_time >= ($cur_time - 3600) && 'Y' == $last_scan_completed)
    {
        $need_scan_music_dir = False;
    }
}

//step 2b, do scan
//DONE: tested this section with detailed output of found files
if($need_scan_music_dir)
{
    $scan_complete = False;
    $scan_filecount = 0;
    $scan_errormessage='';
    $i = 0; // total count of files

    if(is_dir($CONFIG_VAR['MUSIC_DIR_ROOT']))
    {
        //scan algorithm
        $dir_handle = opendir($CONFIG_VAR['MUSIC_DIR_ROOT']);
        $dir_names = array();
        $dir_names[] = $CONFIG_VAR['MUSIC_DIR_ROOT'];
        $cur_dirbase = $CONFIG_VAR['MUSIC_DIR_ROOT'] . '/';
        $dircache = array();
        $files_to_add = array();
        $FILES_UNTIL_DB_FLUSH = 100;
        $j = 0; // files in $files_to_add
        $k = 0;
        $dircache[] = array();
        while(-1 < $k)
        {
            $cur_name = readdir($dir_handle);
            if(!(FALSE === $cur_name))
            {
                if(!('.' == $cur_name || '..' == $cur_name))
                {
                    if(is_dir($cur_dirbase . $cur_name))
                    {
                        $dircache[$k][] = $cur_name;
                    } else // do not discern between regular files and links at this point
                    {
                        $files_to_add[] = new MinimalisticFile($cur_dirbase, $cur_name);
                        $j++;
                        $i++;
                    }
                    if($FILES_UNTIL_DB_FLUSH === $j)
                    {
                        //Do db flush
                        //      either insert or update the respective entries in table `filecache`
                        //      best: use a mysql stored procedure for that, instead of doing checks in PHP
                        //      (1. check if already there 'SELECT', 2. if so do an 'UPDATE', 3 otherwise do 'INSERT')
                        flush_files_to_db($dbcon, $files_to_add);
                        //reset cached files in array
                        $files_to_add = array();
                        $j = 0;
                    }
                }
            } else // finished scanning this directory
            {
                closedir($dir_handle);
                $dir_handle = NULL;
                //pick next directory for next call to readdir
                while(is_null($dir_handle) && -1 < $k)
                {
                    if(0 < count($dircache[$k]))
                    {
                        //descend
                        $descend_to_dir = array_shift($dircache[$k]);
                        $dircache[] = array();
                        $k++;
                        $dir_names[] = $descend_to_dir;
                        $cur_dirbase .= $descend_to_dir . '/';
                        $dir_handle = opendir($cur_dirbase); 
                    } else
                    {
                        //ascend, adjust $cur_dirbase and $dir_names
                        $k--;
                        if(-1 < $k)
                        {
                            //pop the deepest array from $dircache
                            array_pop($dircache);
                            array_pop($dir_names);
                            $cur_dirbase = implode('/', $dir_names) . '/';
                        }
                    }
                    //loop will end if k has been decremented to -1
                }
            }
        }
        //do db flush from $files_to_add
        flush_files_to_db($dbcon, $files_to_add);
    } else
    {
        $scan_errormessage .= 'Configured MUSIC_DIR_ROOT "' . $CONFIG_VAR['MUSIC_DIR_ROOT'] . '" not accessible as a directory.';
    }
    
    //invalidate all files in table filecache which do not have the timestamp of the current scan
    @$dbcon->query('UPDATE `filecache` SET `valid`=\'N\' WHERE `last_scan`!=' . $cur_time);
    //enter scan results into database (i.e. enter $cur_time, $scan_complete, $scan_filecount, $san_errormessage)
    @$dbcon->query('INSERT INTO `scans` (`time`, `completed`, `error_message`, `files_scanned`) VALUES (' . $cur_time . ', \'' . (0 < strlen($scan_errormessage) ? 'N' : 'Y') . '\', \'' . $dbcon->real_escape_string($scan_errormessage) . '\', ' . $i . ')');
}

//step 3b, load all playlists
$playlists_result = @$dbcon->query('SELECT `playlists`.`id` AS \'pid\', `playlists`.`name` AS \'pname\', `playlists`.`thumb_path` AS \'img_path\',`filecache`.`path_str`AS \'file_path\' FROM `playlists` INNER JOIN `relation_playlists` ON `playlists`.`id`=`relation_playlists`.`pid` INNER JOIN `filecache` ON `relation_playlists`.`fid`=`filecache`.`id` WHERE `filecache`.`valid`=\'Y\' ORDER BY `playlists`.`name` ASC, `relation_playlists`.`prank` ASC');
if(!(FALSE === $playlists_result) && 0 < $playlists_result->num_rows)
{
    $loaded_playlists = array();
    $prev_pid = -1;
    $i = -1;
    while($cur_row = $playlists_result->fetch_assoc())
    {
        $cur_pid = (int) $cur_row['pid'];
        if($prev_pid != $cur_pid)
        {
            $i++;
            $loaded_playlists[] = array();
            $loaded_playlists[$i][] = $cur_row['pname'];
            $loaded_playlists[$i][] = $cur_row['img_path'];
            $loaded_playlists[$i][] = array();
            $prev_pid = $cur_pid;
        }
        $loaded_playlists[$i][2][] = $cur_row['file_path'];
    }
}

//step 4, present audio player
?>
<!DOCTYPE html5>
<html>
<head>
<meta charset="utf8" />
<title>juph audio player</title>
<script type="text/javascript">
var searchField;
var searchListWrapper;
var audioPlayer;
var audioCaption;
var playlistWrapper;
var sessionPlaylist;
var playlistEle;
var playlistObj;
var BODY;
var contextMenu;
var sessionId;
function init()
{
  searchField = document.getElementById("search_input");
  searchField.addEventListener("keyup", search_keyup);
  searchListWrapper = document.getElementById("search_list_wrapper");
  audioPlayer = document.getElementById("audio_player");
  playlistWrapper = document.getElementById("playlist_wrapper");
  playlistObj = new PlaylistClass();
  playlistObj.assumePlaylist();
  audioPlayer.addEventListener("wheel", playlistObj.onwheel);
  audioPlayer.addEventListener("play", playlistObj.onplay);
  audioPlayer.addEventListener("ended", playlistObj.trackEnded);
  audioPlayer.addEventListener("error", playlistObj.onerror);
  audioCaption = document.getElementById("audio_caption");
  BODY = document.getElementsByTagName("body")[0];
  BODY.getTotalHeight = function()
  {
    //thanks to 'Borgar'
    // https://stackoverflow.com/a/1147768
    html = document.documentElement;
    return Math.max(BODY.scrollHeight, BODY.offsetHeight,
                    html.clientHeight, html.scrollHeight, html.offsetHeight);
  }
  sessionId = <?php echo "\"" . js_escape($SESSION_ID) . "\";";  ?>
  juffImg.init();
}
var juffImg = {
  imgArr: [
    {
      src: "logo.png",
      width: 178,
      height: 200
    },
    {
      src: "country.png",
      width: 140,
      height:165 
    },
    {
      src: "rock.png",
      width: 151,
      height: 200
    },
    {
      src: "hiphop.png",
      width: 168,
      height: 200
    },
   ],
  ele: undefined,
  init: function()
  {
    juffImg.ele = document.getElementById("juff_img");
    juffImg.setImg(0);
  },
  setImg: function(imgName)
  {
    var match = juffImg.imgArr[0];
    if("string" == (typeof imgName))
    {
      for(var i = 0; i < juffImg.imgArr.length; ++i)
      {
        if(juffImg.imgArr[i].match(imgName))
        {
          match = juffImg.imgArr[i];
          break;
        }
      }
    } else if("number" == (typeof imgName))
    {
      if(-1 < imgName && juffImg.imgArr.length > imgName)
      {
        match = juffImg.imgArr[imgName];
      }
    }
    juffImg.ele.setAttribute("src",    match.src);
    juffImg.ele.setAttribute("width",  match.width);
    juffImg.ele.setAttribute("height", match.height);
  },
  getImgCount: function()
  {
    return juffImg.imgArr.length;
  }
};
function PlaylistClass()
{
  this.boundHtml;
  this.titleHtml;
  this.listHtml;
  this.optionsHtml;
  this.htmlTrackCount = 0;
  this.tracks = new Array();
  this.offset = 0;
  this.previousId = -1;
  this.loop = "none";
  this.myName = "";
  this.playRandom = false;
  this.randomArr = new Array();
  this.randomOffset = 0;
  this.playlistName = undefined;
  this.assumePlaylist = function()
  {
    if(playlistEle)
    {
      playlistEle.parentNode.removeChild(playlistEle);
    }
    var myEle = document.createElement("div");
    myEle.setAttribute("class", "playlist");
    playlistWrapper.appendChild(myEle);
    this.htmlTrackCount = 0;
    this.boundHtml = myEle;
    playlistEle = myEle;
    this.titleHtml = document.createElement("div");
    this.titleHtml.setAttribute("class", "playlist_title");
    this.titleHtml = this.boundHtml.appendChild(this.titleHtml);
    this.listHtml = document.createElement("div");
    this.listHtml.setAttribute("class", "playlist_list");
    this.listHtml = this.boundHtml.appendChild(this.listHtml);
    this.optionsHtml = document.createElement("div");
    this.optionsHtml.setAttribute("class", "playlist_option_wrapper");
    //add icons
    var optionEle,imgEle;
    var optionsImgs = new Array("loopone.png", "loopall.png", "random.png", "save.png", "delete.png");
    for(var i = 0; i < optionsImgs.length; ++i)
    {
      optionEle = document.createElement("div");
      imgEle = document.createElement("img");
      optionEle.setAttribute("class", "playlist_option_div");
      imgEle.setAttribute("class", "playlist_option_img");
      imgEle.setAttribute("src", optionsImgs[i]);
      optionEle.appendChild(imgEle);
      this.optionsHtml.appendChild(optionEle);
    }
    this.optionsHtml = this.boundHtml.appendChild(this.optionsHtml);
    this.optionsHtml.childNodes[0].addEventListener("click", function() { playlistObj.loopClicked("one"); } );
    this.optionsHtml.childNodes[1].addEventListener("click", function() { playlistObj.loopClicked("all"); } );
    this.optionsHtml.childNodes[2].addEventListener("click", playlistObj.randomClicked);
    this.optionsHtml.childNodes[3].addEventListener("click", playlistObj.save);
    this.optionsHtml.childNodes[4].addEventListener("click", playlistObj.clearPlaylist);
  }
  this.enqueueLast = function(trackId, trackType, trackName)
  {
    if("file" == trackType)
    {
      var newTrack = new TrackClass(trackId, trackType, trackName);
      this.tracks.push(newTrack);
      this.addTrackHtml(newTrack, this.tracks.length - 1);
      if(this.playRandom)
      {
        this.randomArr.splice(this.randomOffset + 1 + Math.floor(Math.random() * (this.randomArr.length - 1 - this.randomOffset)), 0, this.tracks.length - 1);
      }
    } else if("playlist" == trackType)
    {
      this.fetchPlaylist(trackId, trackName, "last");
    }
  }
  this.enqueueNext = function(trackId, trackType, trackName)
  {
    if("file" == trackType)
    {
      var newTrack = new TrackClass(trackId, trackType, trackName);
      var newPos = this.offset + 1;
      if(this.tracks.length > newPos)
      {
        this.tracks.splice(newPos, 0, newTrack);
      } else
      {
        this.tracks.push(newTrack);
      }
      this.addTrackHtml(newTrack, newPos);
      if(this.playRandom)
      {
        for(var i = 0; i < this.randomArr.length; ++i)
        {
          if(this.randomArr[i] >= newPos)
          {
            this.randomArr[i] = this.randomArr[i] + 1;
          }
        }
        this.randomArr.splice(this.randomOffset + 1, 0, newPos);
      }
    } else if("playlist" == trackType)
    {
      this.fetchPlaylist(trackId, trackName, "next");
    }
  }
  this.addTrackHtml = function(trackObj, position)
  {
    var trackLink = document.createElement("a");
    trackLink.setAttribute("href", "javascript:playlistObj.playOffset(" + position + ")");
    trackLink.setAttribute("class", "playlist_link");
    var trackEle = document.createElement("div");
    if(position == this.offset)
    {
      trackEle.setAttribute("class", "playlist_element playlist_selected_element");
    } else
    {
      trackEle.setAttribute("class", "playlist_element");
    }
    trackEle.appendChild(document.createTextNode(beautifySongName(basename(trackObj.name))));
    trackEle = trackLink.appendChild(trackEle);
    if(position == (this.htmlTrackCount + 1))
    {
      trackLink = this.listHtml.appendChild(trackLink);
    } else
    {
      this.listHtml.insertBefore(trackLink, this.listHtml.childNodes[position]);
      for(var i = position + 1; i < this.listHtml.childNodes.length; ++i)
      {
        this.updateListElement(i);
      }
    }
    this.htmlTrackCount++;
    trackLink.setAttribute("playlist_offset", position);
    trackLink.addEventListener("contextmenu", function(position) {
      var contextHandler = function(evt) { 
        evt.preventDefault();
        var position = parseInt(evt.target.parentNode.getAttribute("playlist_offset"));
        playlistObj.contextMenuFor(evt.pageX, evt.pageY, evt.target, position);
      };
    return contextHandler;
    }(position), false);
  };
  this.contextMenuFor = function(posX, posY, ele, position)
  {
    new ContextMenuClass(posX, posY, ele, [["Jump To", function(position) {
      return function() { playlistObj.playOffset(position);}; }(position)],
      ["Enqueue Next", function(position) {
      return function() { var swapTrack = playlistObj.tracks[position]; playlistObj.removeTrack(position); playlistObj.enqueueNext(swapTrack.id, swapTrack.type, swapTrack.name); }; }(position)],
      ["Remove", function(position) {
      return function() { playlistObj.removeTrack(position); };}(position)]
    ]);
  };
  this.updateListElement = function(position)
  {
    this.listHtml.childNodes[position].setAttribute("playlist_offset", position);
    this.listHtml.childNodes[position].setAttribute("href", "javascript:playlistObj.playOffset(" + position + ")");
  };
  this.removeTrack = function(position)
  {
    this.tracks.splice(position, 1);
    this.listHtml.removeChild(this.listHtml.childNodes[position]);
    if(position < playlistObj.offset)
    {
      playlistObj.offset--;
    }
    if(position < (this.tracks.length - 1))
    {
      for(var i = position; i < this.tracks.length; ++i)
      {
        this.updateListElement(i);
      }
    }
    if(playlistObj.randomArr && 0 < playlistObj.randomArr.length)
    {
      for(var i = 0; i < playlistObj.randomArr.length; ++i)
      {
        if(playlistObj.randomArr[i] > position)
        {
          playlistObj.randomArr[i]--;
        } else if(playlistObj.randomArr[i] == position)
        {
          playlistObj.randomArr.splice(i, 1);
          if(playlistObj.randomOffset > i)
          {
            playlistObj.randomOffset--;
          }
          i--;
        }
      }
    }
  };
  this.scrollTo = function(offset)
  {
    if(this.listHtml && (this.listHtml.scrollTo || this.listHtml.scroll))
    {
      var cumulativeHeight = 0;
      for(var i = 0; i < offset; ++i)
      {
        //need to get the height of the "div"-element (i.e. firstChild),
        //because chrome refuses to report a height
        //for the surrounding "a"-element
        cumulativeHeight += this.listHtml.childNodes[i].firstChild.offsetHeight;
      }
      if(this.listHtml.scrollTo)
      {
        this.listHtml.scrollTo({
          left: 0,
          top: cumulativeHeight,
          behavior: "smooth"});
      } else
      {
        this.listHtml.scroll(0, cumulativeHeight);
      }
    }
  }
  this.length = function()
  {
    return this.tracks.length;
  }
  this.playOffset = function(newOffset)
  {
    if(this.playRandom)
    {
      this.playRandom = false;
      this.setHtmlOption(2, false);
    }
    if(-1 < newOffset && newOffset < playlistObj.tracks.length)
    {
      this.listHtml.childNodes[this.offset].firstChild.setAttribute("class", "playlist_element");
      playlistObj.offset = newOffset;
      this.listHtml.childNodes[this.offset].firstChild.setAttribute("class", "playlist_element playlist_selected_element");
    }
    playlistObj.play();
  }
  this.play = function(doContinuePlaying)
  {
    try {
      if(this.offset >= this.tracks.length)
      {
        this.offset = 0;
      }
      if(this.offset < this.tracks.length)
      {
        if(this.previousId != this.tracks[this.offset].id)
        {
          var requestUrl = "?ajax&request_track=" + this.tracks[this.offset].id;
          audioPlayer.pause();
          audioPlayer.setAttribute("src", requestUrl);
          audioPlayer.preload = "auto";
          juffImg.setImg(Math.floor(1 + Math.random() * (juffImg.getImgCount() - 1)));
        } else
        {
          if(!doContinuePlaying)
          {
            audioPlayer.currentTime = 0;
          }
        }
        audioPlayer.play();
        removeChilds(audioCaption);
        audioCaption.appendChild(document.createTextNode(this.tracks[this.offset].beautifiedName));
        this.scrollTo(this.offset);
        this.previousId = this.tracks[this.offset].id;
      }
    } catch(exc)
    {
      alert(exc);
    }
  }
  this.advance = function(direction)
  {
    if(0 != direction)
    {
      this.listHtml.childNodes[this.offset].firstChild.setAttribute("class", "playlist_element");
      if(this.playRandom)
      {
        this.randomOffset = (this.randomOffset + direction) % this.randomArr.length;
        this.offset = this.randomArr[this.randomOffset];
      } else
      {
        this.offset = (this.offset + direction) % this.tracks.length;
      }
      this.listHtml.childNodes[this.offset].firstChild.setAttribute("class", "playlist_element playlist_selected_element");
    }
  }
  this.playNext = function()
  {
    playlistObj.advance(1);
    playlistObj.play();
  }
  this.onerror = function(evt)
  {
    if(audioPlayer.networkState == HTMLMediaElement.NETWORK_NO_SOURCE)
    {
      playlistObj.trackEnded(evt);
    } else
    {
      console.log("Miscellenaeous error occured with audio Player.");
      console.log(evt);
    }
  }
  this.onwheel = function(evt)
  {
    evt.preventDefault();
    audioPlayer.currentTime = audioPlayer.currentTime + evt.deltaY;
  }
  this.onplay = function(evt)
  {
    playlistObj.play(true);
  }
  this.trackEnded = function(evt)
  {
    if("none" == playlistObj.loop)
    {
      if(playlistObj.playRandom)
      {
        if((playlistObj.randomOffset + 1) < playlistObj.randomArr.length)
        {
          playlistObj.advance(1);
          playlistObj.play();
        }
      } else
      {
        if((playlistObj.offset + 1) < playlistObj.tracks.length)
        {
          playlistObj.advance(1);
          playlistObj.play();
        }
      }
    } else if("all" == playlistObj.loop)
    {
      playlistObj.advance(1);
      playlistObj.play();
    } else if("one" == playlistObj.loop)
    {
      //don't advance offset
      playlistObj.play();
    }
  }
  this.setHtmlOption = function(optionNumber, optionEnabled)
  {
    if(optionEnabled)
    {
      playlistObj.optionsHtml.childNodes[optionNumber].setAttribute("class","playlist_option_div playlist_option_div_selected");
    } else
    {
      playlistObj.optionsHtml.childNodes[optionNumber].setAttribute("class","playlist_option_div");
    }
  }
  this.randomClicked = function()
  {
    playlistObj.playRandom = ! playlistObj.playRandom;
    playlistObj.setHtmlOption(2, playlistObj.playRandom);

    if(playlistObj.playRandom)
    {
      var randCopy = new Array();
      var randSelect = new Array();
      for(var i = 0; i < playlistObj.tracks.length; ++i)
      {
        if(i == playlistObj.offset)
        {
          randSelect.push(i);
        } else {
          randCopy.push(i);
        }
      }
      while(randCopy.length > 1)
      {
        var selectedIndex = Math.floor(Math.random() * randCopy.length);
        var popped = randCopy.splice(selectedIndex, 1);
        randSelect.push(popped[0]);
      }
      if(0 < randCopy.length) randSelect.push(randCopy[0]);
      playlistObj.randomArr = randSelect;
      playlistObj.randomOffset = 0;
    }
  }
  this.loopClicked = function(loopStr)
  {
    if(loopStr == playlistObj.loop)
    {
      playlistObj.loop = "none";
    } else {
      playlistObj.loop = loopStr;
    }

    playlistObj.setHtmlOption(0, "one" == playlistObj.loop);
    playlistObj.setHtmlOption(1, "all" == playlistObj.loop);
  }
  this.clearPlaylist = function()
  {
    playlistObj.offset = 0;
    playlistObj.tracks = new Array();
    playlistObj.htmlTrackCount = 0;
    removeChilds(audioCaption);
    removeChilds(playlistObj.listHtml);
    audioPlayer.pause();
    playlistObj.previousId = -1;
    audioPlayer.preload = "none";
    /* this causes firefox to complain
     * "Invalid URI. Load of media resource  failed."
     * however, I don't know how to tell firefox to forget
     * what was stored in an audio element. Although I have
     * tried using the recommended DOM-way by adding <source>
     * elements, that does not work either.
     */
    audioPlayer.setAttribute("src", "");

    playlistObj.setPlaylistName(undefined);
  }
  this.fetchPlaylist = function(playlistId, playlistName, enqueueWhere)
  {
    var req = new XMLHttpRequest();
    req.open("GET", "?ajax&request_playlist=" + encodeURIComponent(playlistId));
    req.playlistName = playlistName;
    req.addEventListener("load", function(param) {
      var responseJSON = JSON.parse(param.target.responseText);
      if(responseJSON.success && responseJSON.matches)
      {
        if(0 == playlistObj.tracks.length)
        {
          playlistObj.setPlaylistName(param.target.playlistName);
        }
        for(var i = 0; i < responseJSON.matches.length; ++i)
        {
          if(enqueueWhere == "last") playlistObj.enqueueLast(responseJSON.matches[i].id, responseJSON.matches[i].type, responseJSON.matches[i].name);
          else if(enqueueWhere == "next") playlistObj.enqueueNext(responseJSON.matches[i].id, responseJSON.matches[i].type, responseJSON.matches[i].name);
        }
      }
    });
    req.send();
  }
  this.save = function()
  {
    var returnVal;
    if(playlistObj.playlistName && 0 < playlistObj.playlistName.length)
    {
      returnVal = prompt("Please enter name of Playlist:", playlistObj.playlistName);
    } else
    {
      returnVal = prompt("Please enter name of Playlist:");
    }
    if(returnVal)
    {
      playlistObj.myName = returnVal;
      var idString = "";
      for(var i = 0; i < playlistObj.tracks.length; ++i)
      {
        if(0 < i) idString += ",";
        idString += "" + playlistObj.tracks[i].id;
      }
      var req = new XMLHttpRequest();
      req.open("POST", "?ajax&put_playlist");
      req.addEventListener("load", function(param) { console.log(param.target.responseText); });
      req.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
      req.send("playlist_name=" + encodeURIComponent(playlistObj.myName) + "&playlist_tracks=" + encodeURIComponent(idString));
      playlistObj.setPlaylistName(returnVal);
    }
  }
  this.setPlaylistName = function(playlistName)
  {
    playlistObj.playlistName = playlistName;
    removeChilds(playlistObj.titleHtml);
    if(playlistName)
    {
      playlistObj.titleHtml.appendChild(document.createTextNode("Playlist: " + playlistName));
    }
  }
}
/* TODO: extend this by adding field 'countPlayed' */
function TrackClass(trackId, trackType, trackName, trackCountPlayed, trackTags)
{
  this.id = trackId;
  this.type = trackType;
  this.countPlayed = trackCountPlayed;
  this.tags = ("" + trackTags).split(",");
  this.name = trackName;
  this.beautifiedName = this.name;
  if("file" == this.type)
  {
    this.beautifiedName = basename(this.beautifiedName);
    this.beautifiedName = beautifySongName(this.beautifiedName);
  } else if("playlist" == this.type)
  {
    this.beautifiedName = "PL: " + this.name;
  }
}

function ContextMenuClass(posX, posY, parentNode, optionsArr)
{
  this.menuEle;
  this.itemsArr;
  this.overlayArr;
  if(contextMenu)
  {
    if(contextMenu.selfDestruct) contextMenu.selfDestruct();
  }
  contextMenu = this;
  this.selfDestruct = function()
  {
    if(contextMenu.overlayArr)
    {
      for(var i = 0; i < contextMenu.overlayArr.length; i++)
      {
        contextMenu.overlayArr[i].parentNode.removeChild(contextMenu.overlayArr[i]);
      }
    }
    contextMenu.menuEle.parentNode.removeChild(contextMenu.menuEle);
    contextMenu = undefined;
  }
  if(!parentNode)
  {
    this.overlayArr = new Array();
    this.overlayArr.push(document.createElement("div"));
    this.overlayArr[0].setAttribute("class", "overlay_veil");
    this.overlayArr[0] = BODY.appendChild(this.overlayArr[0]);
    this.overlayArr[0].addEventListener("click", function() {if(contextMenu && contextMenu.selfDestruct) contextMenu.selfDestruct();});
  } else
  {
    /* TODO: if parentNode is given, paint a veil around the parentNode
     */
    this.overlayArr = new Array();
    this.overlayArr.push(document.createElement("div"));
    this.overlayArr[0].setAttribute("class", "overlay_veil");
    this.overlayArr[0].style.position = "absolute";
    this.overlayArr[0].style.left=0;
    this.overlayArr[0].style.top =0;
    this.overlayArr[0] = BODY.appendChild(this.overlayArr[0]);
    this.overlayArr[0].addEventListener("click", function() {if(contextMenu && contextMenu.selfDestruct) contextMenu.selfDestruct();});
  }
  this.menuEle = document.createElement("div");
  this.menuEle.setAttribute("class", "contextmenu_wrapper");
  this.menuEle.style.position = "absolute";
  if((posY + (optionsArr.length * 35) + 5) < BODY.getTotalHeight())
  {
    this.menuEle.style.top = posY;
  } else
  {
    this.menuEle.style.top = posY - (optionsArr.length * 35 + 5);
  }
  if(posX + 150 < BODY.offsetWidth)
  {
    this.menuEle.style.left = posX;
  } else
  {
    this.menuEle.style.left = posX - 150;
  }
  var closeEle = document.createElement("div");
  closeEle.setAttribute("class", "contextmenu_close");
  closeEle.appendChild(document.createTextNode("X"));
  closeEle.addEventListener("click", function() {if(contextMenu && contextMenu.selfDestruct) contextMenu.selfDestruct();});
  this.menuEle.appendChild(closeEle);
  for(var i = 0; i < optionsArr.length; ++i)
  {
    var curEle = document.createElement("div");
    curEle.appendChild(document.createTextNode(optionsArr[i][0]));
    curEle.addEventListener("click", function(callback) { return function() {callback(); contextMenu.selfDestruct();} }(optionsArr[i][1]), false);
    curEle.setAttribute("class", "contextmenu_item");
    this.menuEle.appendChild(curEle);
  }
  this.menuEle.style.height = "calc(" + Math.max(2, optionsArr.length * 2) + "em - 5px)";
  BODY.appendChild(this.menuEle);
}

function onTagClicked(tagName)
{
  searchField.value = tagName;
  ajax_matching_tracks(tagName, 0);
}
function search_keyup(eventObj)
{
  var searchSubject = searchField.value;
  if(2 < searchSubject.length)
  {
    ajax_matching_tracks(searchSubject,0);
  }
}
function ajax_matching_tracks(searchSubject, offset)
{
  var ajax = new XMLHttpRequest();
  ajax.open("GET", "?ajax&matching_tracks=" + encodeURIComponent(searchSubject) + "&matching_offset=" + encodeURIComponent(offset));
  ajax.addEventListener("load", function(param) {
    console.log("Request took " + (((new Date()).getTime() - param.target.requestSendedTime)/1000) + " seconds");
    process_matching_tracks(param.target.responseText, param.target.requestSendedTime);
   });
  ajax.requestSendedTime = (new Date()).getTime();
  ajax.send();
}
var currentTracklist = undefined;
function Tracklist(tracklistJSON, requestSendedTime)
{
  this.tracks = new Array();
  this.pageLimit = 100;
  this.pageOffset = 0;
  this.matchCount = 0;
  this.requestSendedTime = requestSendedTime;
  if(tracklistJSON.success)
  {
    this.matchCount = tracklistJSON.countMatches;
    this.pageOffset = tracklistJSON.offsetMatches;
    this.pageLimit  = tracklistJSON.pageLimit;
    for(var i = 0; i < tracklistJSON.matches.length; ++i)
    {
      this.tracks.push(new TrackClass(tracklistJSON.matches[i].id, tracklistJSON.matches[i].type, tracklistJSON.matches[i].name, tracklistJSON.matches[i].countPlayed, tracklistJSON.matches[i].tags));
    }
  }
  /* TODO: split up the code of this function
   *  into separate specialized functions
   *  because it's too long
   */
  this.assumeSearchList = function()
  {
    removeChilds(searchListWrapper);
    if(this.matchCount > this.pageLimit)
    {
      var curPage = Math.floor(this.pageOffset / this.pageLimit);
      var maxPages = Math.ceil(this.matchCount / this.pageLimit);
      var showPages = new Array();
      for(var i = curPage - 2; i < (curPage + 3); ++i)
      {
        if(i >= 0 && i < maxPages)
        {
          showPages.push(i);
        }
      }
      if(1 < showPages.length)
      {
        if(0 < showPages[0])
        {
          showPages.unshift(0);
        }
        if(maxPages > (showPages[showPages.length - 1] + 1))
        {
          showPages.push(maxPages - 1);
        }
        var pageNumEle = document.createElement("div");
        pageNumEle.setAttribute("class", "paging_wrapper");
        for(var i = 0; i < showPages.length; ++i)
        {
          var fillerEle = document.createElement("span");
          fillerEle.setAttribute("class", "paging_filler");
          if(0 < i && 1 < Math.abs(showPages[i] - showPages[i - 1]))
          {
            fillerEle.appendChild(document.createTextNode(" ... "));
          } else
          {
            fillerEle.appendChild(document.createTextNode("  "));
          }
          pageNumEle.appendChild(fillerEle);
          var curPageNumEle = document.createElement("a");
          var className = "paging_button";
          if(0 == i) className += " paging_button_first";
          if((showPages.length - 1) == i) className += " paging_button_last";
          curPageNumEle.setAttribute("class", className);
          if(showPages[i] != curPage)
          {
            curPageNumEle.setAttribute("href", "javascript:ajax_matching_tracks(searchField.value," + showPages[i] * this.pageLimit + ")");
          }
          curPageNumEle.appendChild(document.createTextNode("" + (showPages[i] + 1)));
          pageNumEle.appendChild(curPageNumEle);
        }
        var trailingEle = document.createElement("span");
        trailingEle.setAttribute("class", "paging_trailing");
        pageNumEle.appendChild(trailingEle);
        searchListWrapper.appendChild(pageNumEle);
      }
    }
    for(var i = 0; i < this.tracks.length; ++i)
    {
      var linkEle = document.createElement("a");
      linkEle.setAttribute("href", "javascript:searchTrackLeftclicked(" + this.tracks[i].id + ", \"" + this.tracks[i].type + "\", \"" + this.tracks[i].name + "\")");
      linkEle.addEventListener("contextmenu", function (listEle, trackId, trackType, trackName) { return function (evt) { evt.preventDefault(); searchTrackRightclicked(evt, listEle, trackId, trackType, trackName); }; }(linkEle, this.tracks[i].id, this.tracks[i].type, this.tracks[i].name));
      linkEle.setAttribute("class", "search_list_link");
      var divEle = document.createElement("div");
      divEle.setAttribute("class", "search_list_element");
      divEle.appendChild(document.createTextNode(this.tracks[i].beautifiedName));
      for(var j = 0; j < this.tracks[i].tags.length; ++j)
      {
        var tagEle = document.createElement("div");
        var tagName = this.tracks[i].tags[j];
        tagEle.setAttribute("class", "search_list_tag");
        tagEle.setAttribute("title", "search for \"" + tagName + "\" by right-clicking");
        tagEle.appendChild(document.createTextNode(tagName));
        tagEle.addEventListener("contextmenu", function(tagName) { return function(evt) { evt.stopPropagation(); evt.preventDefault(); onTagClicked(tagName); }; }(tagName));
        divEle.appendChild(tagEle);
      }
      var countPlayedEle = document.createElement("div");
      countPlayedEle.setAttribute("class", "search_list_count_played");
      var playedText = "";
      if(1 > this.tracks[i].countPlayed)
      {
          playedText = "not yet played";
      } else if (1 == this.tracks[i].countPlayed)
      {
          playedText = "1 time played";
      } else
      {
          playedText = this.tracks[i].countPlayed + " times played";
      }
      countPlayedEle.appendChild(document.createTextNode(playedText));
      divEle.appendChild(countPlayedEle);

      linkEle.appendChild(divEle);
      searchListWrapper.appendChild(linkEle);
    }
  }
}
function process_matching_tracks(responseText, requestSendedTime)
{
  if(currentTracklist && currentTracklist.requestSendedTime > requestSendedTime)
  {
    return;
  }
  removeChilds(searchListWrapper);
  var responseJSON;
  try
  {
    responseJSON = JSON.parse(responseText);
  } catch(exc)
  {
    console.log(exc);
    searchListWrapper.appendChild(document.createTextNode("JS-Error: Could not parse server response as JSON."));
    return;
  }
  if(responseJSON)
  {
    currentTracklist = new Tracklist(responseJSON, requestSendedTime);
    currentTracklist.assumeSearchList();
  }
}
function searchTrackRightclicked(evt, listEle, trackId, trackType, trackName)
{
  new ContextMenuClass(evt.pageX, evt.pageY, evt.target, [["Enqueue", function(trackId,trackName){ return function(evt) {
      playlistObj.enqueueLast(trackId, trackType, trackName);
      if(1 == playlistObj.length())
      {
        playlistObj.play();
      }
    }; }(trackId, trackName)],[ "Enqueue Next", function(trackId,trackName){ return function(evt) {
      playlistObj.enqueueNext(trackId, trackType, trackName);
      if(1 == playlistObj.length())
      {
        playlistObj.play();
      }
    }; }(trackId, trackName)],["Play", function(trackId,trackName){ return function(evt) {
      playlistObj.clearPlaylist();
      playlistObj.enqueueLast(trackId, trackType, trackName);
      playlistObj.play();
    }; }(trackId, trackName)]]);
}
function searchTrackLeftclicked(trackId, trackType, trackName)
{
  playlistObj.enqueueLast(trackId, trackType, trackName);
  if(1 == playlistObj.length())
  {
    playlistObj.play();
  }
}
function removeChilds(parentNode)
{
  for(var i = parentNode.childNodes.length - 1; i >= 0; i--)
  {
    parentNode.removeChild(parentNode.childNodes[i]);
  }
}
function basename(filepath)
{
  var matchEnd = filepath.match(/[^/]+$/);
  return matchEnd[0];
}
function beautifySongName(filename)
{
  var beautified = filename.replace(/\.[a-zA-Z0-9]{1,6}$/, "");
  beautified = beautified.replace(/_id[-_a-zA-Z0-9]{4,15}$/, "");
  beautified = beautified.replace(/_/g, " ");
  beautified = beautified.replace(/^ +/g, "");
  beautified = beautified.replace(/ +$/g, "");
  beautified = beautified.replace(/ HD$/i, "");
  beautified = beautified.replace(/ []$/, "");
  beautified = beautified.replace(/Official Music Video$/i, "");
  beautified = beautified.replace(/Music Video$/i, "");
  beautified = beautified.replace(/Official Video$/i, "");
  beautified = beautified.replace(/Official Video HQ$/i, "");
  beautified = beautified.replace(/Original HQ$/i, "");
  beautified = beautified.replace(/Official Video VOD$/i, "");
  beautified = beautified.replace(/Videoclip$/i, "");
  beautified = beautified.replace(/\(official\) /i, "");
  var withoutLeadingNumbers = beautified.replace(/^[0-9]{1,4} ?(- )?/, "");
  if(1 < withoutLeadingNumbers.length)  beautified = withoutLeadingNumbers;
  beautified = beautified.replace(/^[-~.] */, "");
  return beautified;
}
document.addEventListener("DOMContentLoaded", init);
</script>
<style type="text/css">
body {
  font-family: Verdana, Sans, Sans-Serif;
}
.content_wrapper {
  margin: 0px auto 0px auto;
  width: 90%;
}
.left_wrapper {
  float: left;
  width: 45%;
}
.right_wrapper {
  float: right;
  width: 45%;
  background-color: #f0f0f0;
  border-radius: 20px;
  padding: 1em;
}
.search_input {
  width: 100%;
  font-size: 20pt;
  letter-spacing: 1px;
  margin: 0.3em 0em 0em 0em;
  background-image: url(looking-glass.png);
  background-repeat: no-repeat;
  background-position: calc(100% - 10px) 0px;
  border-width: 4px;
  border-radius: 5px;
}
.search_list_wrapper {
}
.search_list_element {
  border-bottom: 2px solid #e0e0e0;
  margin: 0.4em 0em 0.4em 0em;
  padding: 0em 0em 0.2em 0.3em;
  overflow: hidden;
}
.search_list_tag {
  font-size: 10pt;
  background-color: #dbdbdb;
  color: #404040;
  float: right;
  margin-left: 6px;
  padding: 1px 3px 1px 3px;
  border-radius: 5px;
}
.search_list_tag:hover {
  background-color: #000000;
  color: #ffffff;
}
.search_list_count_played {
  font-size: 10pt;
  clear: both;
  color: #606060;
}
.search_label {
  font-size: 14pt;
}
.search_list_link:link, .search_list_link:visited {
  color: #000000;
  text-decoration: none;
}
.paging_wrapper {
  font-family: Sans, Sans-Serif, Arial;
  margin: 0.3em 0em 0.3em 0em;
}
.paging_filler {
  display: block;
  float: left;
  min-width: 1.5em;
  text-align: center;
}
.paging_button {
  display: block;
  float: left;
  min-width: 1.5em;
  background-color: #ffffff;
  /*margin: 0em 0.2em 0em 0.2em;*/
  padding: 0.2em;
  text-align: center;
  color: #000000;
  font-weight: bold;
  font-size: 14pt;
  border-left:  3px solid #f0f0f0;
  border-right: 3px solid #f0f0f0;
}
.paging_button:hover
{
  transform: scale(1.2);
}
.paging_button_first {
  display: block;
  margin-left: 0em;
  border-left: none;
  border-top-left-radius: 9px;
  border-bottom-left-radius: 9px;
}
.paging_button_last {
  display: block;
  border-right: none;
  border-top-right-radius: 9px;
  border-bottom-right-radius: 9px;
}
.paging_button:link, .paging_button:visited {
  text-decoration: none;
  color: #606060;
  font-weight: normal;
}
.paging_trailing {
  display: block;
  clear: both;
  width: 0px;
  height: 0px;
  margin: 0px;
}
#audio_caption {
  font-size: 20pt;
  font-weight: bold;
  min-height: 20px;
  margin: 0.1em 0em 0.3em 0em;
}
#audio_player {
  width: 100%;
}
.playlist {
  background-color: #000000;
  color: #ffffff;
}
.playlist_title {
  font-size: 16pt;
  padding: 2px 0px 3px 10px;
  background-color: #f0f0f0;
  color: #404040;
  font-weight: bold;
  border-bottom: 3px solid #808080;
  background-image: radial-gradient(circle at 20%, #ffffff 0%, #c8c8c8 100%);
  box-shadow: inset 0px -5px 10px 3px #c0c0c0;
}
.playlist_list {
  max-height: 250px;
  overflow: auto;
}
.playlist_link:link, .playlist_link:visited {
  color: #000000;
  text-decoration: none;
}
.playlist_element {
  border-bottom: 2px solid #a0a0a0;
  background-color: #c0c0c0;
  color: #202020;
  overflow: hidden;
  padding: 0.15em 0em 0.45em 0.1em;
  box-shadow: inset 0px -3px 3px 2px #808080;
}
.playlist_selected_element {
  color: #000000;
  background-color: #ffffff;
  box-shadow: none;
  background-image: linear-gradient(to bottom, #ffffff 0%, #e8e8e8 80%, #a0a0a0 100%);
}
.playlist_selected_element::before {
  content: "▶ ";
}
.playlist_option_wrapper {
  background-color: #808080;
  width: 100%;
  height: 42px;
}
.playlist_option_div {
  float: left;
  width: calc(100% / 6);
  height: 34px;
  padding: 4px;
  background-color: #808080;
  text-align: center;
  background-image: radial-gradient(ellipse at center, #000000 0%, #080808 20%, #303030 40%, #707070 80%, #b0b0b0 100%);
/*
  border-top: 2px solid #e8e8e8;
  border-left: 2px solid #e8e8e8;
  border-bottom: 2px solid #909090;
  border-right: 2px solid #909090;
*/
}
.playlist_option_div:nth-child(1)
{
  margin-left: 2px;
}
.playlist_option_div:nth-last-child(1)
{
  margin-right: 0px;
}
.playlist_option_div:hover
{
  background-color: #707070;
  background-image: radial-gradient(ellipse at center, #b0b0b0 0%, #707070 20%, #303030 40%, #080808 80%, #000000 100%);
}
.playlist_option_div_selected {
  border: 4px solid #ffffff;
  padding: 0px;
}
.playlist_option_img {
}
.contextmenu_wrapper {
  font-family: Sans, Sans-Serif, Arial;
  width: 8em;
  border: 3px solid #808080;
  border-radius: 10px;
  background-color: #ffffff;
  overflow: hidden;
  border-top-right-radius: 0px;
}
.contextmenu_close {
  float: right;
  width: 22px;
  height: 27px;
  background-color: #d0d0d0;
  cursor: pointer;
  font-size: 21px;
  padding: 0px 0px 0px 5px;
  border: 2px solid #808080;
  border-right: none;
  border-top: none;
}
.contextmenu_close:hover {
  transform: scale(1.2);
}
.contextmenu_item {
  background-color: #f0f0f0;
  padding: 0.3em;
  margin: 0em 0em 0.2em 0em;
  border-radius: 10px;
  cursor: pointer;
}
.contextmenu_item:hover {
  background-color: #505050;
  color: #f0f0f0;
}
.overlay_veil {
  position: fixed;
  left: 0px;
  top: 0px;
  width: 100%;
  height: 100%;
  background-color: rgba(64,64,64,0.2);
}
</style>
</head>
<body>
<div class="content_wrapper">
<div class="left_wrapper">
<img id="juff_img" src="logo.png" width="178" height="200" alt="juph logo"/><br/>
<div id="audio_caption">
</div>
<audio id="audio_player" controls>
</audio>
<div id="playlist_wrapper">
</div>
</div>
<div class="right_wrapper">
<label for="search_input" class="search_label">Search:</label><br/>
<input type="text" id="search_input" class="search_input" size="20" />
<div id="search_list_wrapper" class="search_list_wrapper">
</div>
</div>
</div>
</body>
</html>
<?php

?>
