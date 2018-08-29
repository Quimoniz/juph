<?php
/*
 * Steps:
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
 */
$CONFIG_FILE = 'config.ini';
$CONFIG_VAR = NULL;
$do_setup = true;
$cur_time = time();

class MinimalisticFile {
    public $path = '';
    public $name = '';
    public $size = 0;
    public function __construct($basedir, $filename)
    {
        $this->path = $basedir . $filename; 
        $this->name = $filename;
        $this->size = filesize($this->path);
    }
}

function server_error($message = "Internal Server Error")
{
    header('HTTP/1.1 500 Server error');
    echo "<!DOCTYPE html>\n<html>\n<head>\n<title>Server Error</title>\n</head>\n";
    echo "<h1>Internal Server Error</h1>\n";
    echo "<div>" . htmlspecialchars($message) . "</div>\n";
    echo "<body>\n</body>\n</html>";
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
        $escaped_filename = $dbcon->real_escape_string($cur_file->path);
        if(0 < $i)
        {
            $sql_values .= ', ';
        }
        $sql_values .= '(NULL, \'' . $hashed_filename . '\', \'' . $escaped_filename . '\', ' . $cur_time . ', ' . $cur_file->size . ')';
        $i++;
    }
    @$dbcon->query('INSERT INTO `filecache` (`id`, `path_hash`, `path_str`, `last_scan`, `size`) ' . $sql_values . ' ON DUPLICATE KEY UPDATE `last_scan` = VALUES(`filecache`.`last_scan`)');
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
    if(isset($_GET['ajax']))
    {
        server_error("Ajax unavailable");
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
    $create_sql .= '`last_scan` BIGINT NOT NULL,';
    $create_sql .= '`size` BIGINT NOT NULL,';
    $create_sql .= '`valid` ENUM(\'Y\',\'N\') NOT NULL DEFAULT \'Y\',';
    // maybe TODO for later: more fields e.g. hash, len
    $create_sql .= 'KEY(`id`),';
    $create_sql .= 'INDEX(`path_hash`)';
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
            server_error('Error during creation of tables (errno: ' . $dbcon->errno . '): ' . htmlspecialchars($dbcon->error));
            exit(0);
        }
    }
}

//step 1c, handle ajax
if(isset($_GET['ajax']))
{
    if($_GET['matching_tracks'])
    {
        $search_subject = $_GET['matching_tracks'];
        if(3 > strlen($search_subject))
        {
            echo "error";
        
        } else
        {
            $search_subject = $dbcon->real_escape_string($search_subject);
            $result_matches = @$dbcon->query('SELECT `path_str` FROM `filecache` WHERE `path_str` LIKE \'%' . $search_subject . '%\' ORDER BY `path_str` ASC LIMIT 100');
            if(!(FALSE === $result_matches))
            {
                echo "{\n\"matches\": [";
                $i = 0;
                while($cur_row = $result_matches->fetch_assoc())
                {
                    if(0 < $i) echo ",\n";
                    echo "{ \"name\": \"" . str_replace(array("\\", "\"","\n"), array("\\\\", "\\\"", "\\n"), $cur_row['path_str']) . "\"}";
                    $i++;
                }
                echo "]\n}";
            }
        }
    } else if($_GET['request_track'])
    {
        $target_file = $_GET['request_track'];
        if(0 === strpos($target_file, $CONFIG_VAR['MUSIC_DIR_ROOT']) && FALSE === strpos($target_file, '/../'))
        {
            if(file_exists($target_file))
            {
                $target_data = file_get_contents($target_file);
                header('Content-Type: audio/mp3');
                echo $target_data;
            }
        }
    }
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
//TODO: test this section with detailed output of found files
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
function init()
{
  searchField = document.getElementById("search_input");
  searchField.addEventListener("keyup", search_keyup);
  searchListWrapper = document.getElementById("search_list_wrapper");

}
function search_keyup(eventObj)
{
  var searchSubject = searchField.value;
  if(2 < searchSubject.length)
  {
    ajax_matching_tracks(searchSubject);
  }
}
var ajax;
function ajax_matching_tracks(searchSubject)
{
  ajax = new XMLHttpRequest();
  ajax.open("GET", "?ajax&matching_tracks=" + encodeURIComponent(searchSubject));
  ajax.addEventListener("load", function() {
    removeChilds(searchListWrapper);
    var responseJSON = JSON.parse(ajax.responseText);
    for(var i = 0; i < responseJSON.matches.length; ++i)
    {
      var newMatch = document.createElement("a");
      newMatch.appendChild(document.createTextNode(basename(responseJSON.matches[i].name)));
      newMatch.setAttribute("href", "javascript:ajax_request_track('" + responseJSON.matches[i].name + "')");
      searchListWrapper.appendChild(newMatch);
      searchListWrapper.appendChild(document.createElement("br"));
    }
   });
  ajax.send();
}
function ajax_request_track(trackpath)
{
  var played = new Audio("?ajax&request_track=" + encodeURIComponent(trackpath));
  played.preload = "auto";
  played.play();
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
document.addEventListener("DOMContentLoaded", init);
</script>
<style type="text/css">
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
}
.search_input {
  width: 100%;
  background-image: url(looking-glass.png);
  background-repeat: no-repeat;
  background-position: calc(100% - 20px) 0px;
}
.search_list_wrapper {
}
</style>
</head>
<body>
<div class="content_wrapper">
<div class="left_wrapper">
<img src="logo.png" width="200" height="215" alt="juph logo"/><br/>
<audio id="audio_player" controls>
</audio>
</div>
<div class="right_wrapper">
<input type="text" id="search_input" class="search_input" size="20" />
<div id="search_list_wrapper" class="search_list_wrapper">
</div>
</div>
</div>
</body>
</html>
<?php


?>
