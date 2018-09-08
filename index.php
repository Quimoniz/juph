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
 */
$CONFIG_FILE = 'config.php';
$CONFIG_VAR = NULL;
$do_setup = true;
$cur_time = time();

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
if(isset($_GET['access_pwd']) && 0 === strcmp($CONFIG_VAR['ACCESS_PWD'], $_GET['access_pwd']))
{
    $access_granted = true;
    $access_setcookie = true;
}
if(isset($_POST['access_pwd']) && 0 === strcmp($CONFIG_VAR['ACCESS_PWD'], $_POST['access_pwd']))
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
<form method="POST" action="">
<input type="password" name="access_pwd" size="30" />
<input type="submit" />
</form>
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
$AJAX_PAGE_LIMIT = 25;
if(isset($_GET['ajax']))
{
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
            $result_matches = @$dbcon->query('SELECT COUNT(`id`) as \'count_matches\' FROM `filecache` WHERE `valid`=\'Y\' AND `path_str` LIKE \'%' . $search_subject . '%\'');
            if(!(FALSE === $result_matches))
            {
                $count_matches = $result_matches->fetch_assoc();
                $count_matches = (int) $count_matches['count_matches'];
            }
            if(0 < $count_matches)
            {
                $result_matches = @$dbcon->query('SELECT `id`,`path_str` FROM `filecache` WHERE `valid`=\'Y\' AND `path_str` LIKE \'%' . $search_subject . '%\' ORDER BY `path_str` ASC LIMIT ' . $search_offset . ',' . $AJAX_PAGE_LIMIT);
                if(!(FALSE === $result_matches))
                {
                    echo "{\n\"success\": true,\n\"countMatches\":";
                    echo $count_matches . ",\n\"pageLimit\":" . $AJAX_PAGE_LIMIT;
                    echo ",\n\"offsetMatches\":" . $search_offset . ",\n\"matches\": [\n";
                    $i = 0;
                    while($cur_row = $result_matches->fetch_assoc())
                    {
                        if(0 < $i) echo ",\n";
                        echo "{ \"id\": " . $cur_row['id'] . ",\"name\": \"" . js_escape($cur_row['path_str']) . "\"}";
                        $i++;
                    }
                    echo "]\n}";
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
                    $target_data = file_get_contents($result_path);
                    header('Content-Type: audio/mp3');
                    echo $target_data;
                }
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
var audioPlayer;
var audioCaption;
var playlistWrapper;
var sessionPlaylist;
var playlistEle;
var playlistObj;
var juffImgEle;
var BODY;
var contextMenu;
function init()
{
  searchField = document.getElementById("search_input");
  searchField.addEventListener("keyup", search_keyup);
  searchListWrapper = document.getElementById("search_list_wrapper");
  audioPlayer = document.getElementById("audio_player");
  playlistWrapper = document.getElementById("playlist_wrapper");
  playlistObj = new PlaylistClass();
  playlistObj.assumePlaylist();
  audioPlayer.addEventListener("ended", playlistObj.playNext);
  audioCaption = document.getElementById("audio_caption");
  juffImgEle = document.getElementById("juff_img");
  BODY = document.getElementsByTagName("body")[0];
}
function PlaylistClass()
{
  this.boundHtml;
  this.htmlTrackCount = 0;
  this.tracks = new Array();
  this.offset = 0;
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
  }
  this.enqueueLast = function(trackId, trackName)
  {
    var newTrack = new TrackClass(trackId, trackName);
    this.tracks.push(newTrack);
    this.addTrackHtml(newTrack, this.tracks.length - 1);
  }
  this.enqueueNext = function(trackId, trackName)
  {
    var newTrack = new TrackClass(trackId, trackName);
    if(this.tracks.length > (this.offset + 1))
    {
      this.tracks.splice(this.offset + 1, 0, newTrack);
    } else
    {
      this.tracks.push(newTrack);
    }
    this.addTrackHtml(newTrack, this.offset + 1);
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
    trackLink.appendChild(trackEle);
    if(position == (this.htmlTrackCount + 1))
    {
      this.boundHtml.appendChild(trackLink);
    } else
    {
      this.boundHtml.insertBefore(trackLink, this.boundHtml.childNodes[position]);
      for(var i = position + 1; i < this.boundHtml.childNodes.length; ++i)
      {
        this.boundHtml.childNodes[i].setAttribute("href", "javascript:playlistObj.playOffset(" + i + ")");
      }
    }
    this.htmlTrackCount++;
  }
  this.scrollTo = function(offset)
  {
    if(this.boundHtml)
    {
      var cumulativeHeight = 0;
      for(var i = 0; i < offset; ++i)
      {
        cumulativeHeight += this.boundHtml.childNodes[i].offsetHeight;
      }
      this.boundHtml.scrollTo({
        top: cumulativeHeight,
        left: 0,
        behavior: "smooth"});
    }
  }
  this.length = function()
  {
    return this.tracks.length;
  }
  this.playOffset = function(newOffset)
  {
    if(-1 < newOffset && newOffset < playlistObj.tracks.length)
    {
      this.boundHtml.childNodes[this.offset].firstChild.setAttribute("class", "playlist_element");
      playlistObj.offset = newOffset;
      this.boundHtml.childNodes[this.offset].firstChild.setAttribute("class", "playlist_element playlist_selected_element");
    }
    playlistObj.play();
  }
  this.play = function()
  {
    if(this.offset >= this.tracks.length)
    {
      this.offset = 0;
    }
    if(this.offset < this.tracks.length)
    {
      var requestUrl = "?ajax&request_track=" + this.tracks[this.offset].id;
      audioPlayer.pause();
      audioPlayer.setAttribute("src", requestUrl);
      audioPlayer.preload = "auto";
      audioPlayer.play();
      removeChilds(audioCaption);
      audioCaption.appendChild(document.createTextNode(this.tracks[this.offset].name));
      juffImgEle.setAttribute("src", "country.png");
      juffImgEle.setAttribute("width", "170");
      juffImgEle.setAttribute("height", "200");
      this.scrollTo(this.offset);
    }
  }
  this.advance = function(direction)
  {
    if(0 != direction)
    {
      this.boundHtml.childNodes[this.offset].firstChild.setAttribute("class", "playlist_element");
      this.offset = (this.offset + direction) % this.tracks.length;
      this.boundHtml.childNodes[this.offset].firstChild.setAttribute("class", "playlist_element playlist_selected_element");
    }
  }
  this.playNext = function()
  {
    playlistObj.advance(1);
    playlistObj.play();
  }
  this.clearPlaylist = function()
  {
    this.offset = 0;
    this.tracks = new Array();
    this.htmlTrackCount = 0;
    removeChilds(this.boundHtml);
  }
}
function TrackClass(trackId, trackName)
{
  this.id = trackId;
  this.name = trackName;
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
    process_matching_tracks(param.target.responseText);
   });
  ajax.send();
}
var currentTracklist = undefined;
function Tracklist(tracklistJSON)
{
  this.tracks = new Array();
  this.pageLimit = 100;
  this.pageOffset = 0;
  this.matchCount = 0;
  if(tracklistJSON.success)
  {
    this.matchCount = tracklistJSON.countMatches;
    this.pageOffset = tracklistJSON.offsetMatches;
    this.pageLimit  = tracklistJSON.pageLimit;
    for(var i = 0; i < tracklistJSON.matches.length; ++i)
    {
      this.tracks.push(new TrackClass(tracklistJSON.matches[i].id, tracklistJSON.matches[i].name));
    }
  }
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
      var fileBase = basename(this.tracks[i].name);
      fileBase = beautifySongName(fileBase);
      var linkEle = document.createElement("a");
      linkEle.setAttribute("href", "javascript:searchTrackLeftclicked(" + this.tracks[i].id + ", \"" + fileBase + "\")");
      linkEle.addEventListener("contextmenu", function (listEle, trackId, trackName) { return function (evt) { evt.preventDefault(); searchTrackRightclicked(evt, listEle, trackId, trackName); }; }(linkEle, this.tracks[i].id, fileBase));
      linkEle.setAttribute("class", "search_list_link");
      var divEle = document.createElement("div");
      divEle.setAttribute("class", "search_list_element");
      divEle.appendChild(document.createTextNode(fileBase));
      linkEle.appendChild(divEle);
      searchListWrapper.appendChild(linkEle);
    }
  }
}
function process_matching_tracks(responseText)
{
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
    currentTracklist = new Tracklist(responseJSON);
    currentTracklist.assumeSearchList();
  }
}
function clearContextMenu()
{
  if(contextMenu)
  {
    contextMenu.parentNode.removeChild(contextMenu);
    contextMenu = undefined;
  }
}
function searchTrackRightclicked(evt, listEle, trackId, trackName)
{
  if(contextMenu)
  {
    clearContextMenu();
  }
  var contextWrapper = document.createElement("div");
  contextWrapper.style.position = "absolute";
  contextWrapper.style.top = evt.pageY;
  contextWrapper.style.left = evt.pageX;
  contextWrapper.setAttribute("class", "search_context_wrapper");
  var enqueueEle = document.createElement("div");
  enqueueEle.appendChild(document.createTextNode("Enqueue"));
  enqueueEle.setAttribute("class", "search_context_element");
  enqueueEle.addEventListener("click", function(trackId,trackName){ return function(evt) {
      playlistObj.enqueueLast(trackId, trackName);
      if(1 == playlistObj.length())
      {
        playlistObj.play();
      }
      clearContextMenu();
    }; }(trackId, trackName), false);
  contextWrapper.appendChild(enqueueEle);
  var enqueueNextEle = document.createElement("div");
  enqueueNextEle.appendChild(document.createTextNode("Enqueue Next"));
  enqueueNextEle.setAttribute("class", "search_context_element");
  enqueueNextEle.addEventListener("click", function(trackId,trackName){ return function(evt) {
      playlistObj.enqueueNext(trackId, trackName);
      if(1 == playlistObj.length())
      {
        playlistObj.play();
      }
      clearContextMenu();
    }; }(trackId, trackName), false);
  contextWrapper.appendChild(enqueueNextEle);
  var playEle = document.createElement("div");
  playEle.appendChild(document.createTextNode("Play"));
  playEle.setAttribute("class", "search_context_element");
  playEle.addEventListener("click", function(trackId,trackName){ return function(evt) {
      playlistObj.clearPlaylist();
      playlistObj.enqueueLast(trackId, trackName);
      playlistObj.play();
      clearContextMenu();
    }; }(trackId, trackName), false);
  contextWrapper.appendChild(playEle);
  var dismissEle = document.createElement("div");
  dismissEle.appendChild(document.createTextNode(" X "));
  dismissEle.setAttribute("class", "search_context_element");
  dismissEle.addEventListener("click", clearContextMenu, false);
  contextWrapper.appendChild(dismissEle);
  
  contextMenu = BODY.appendChild(contextWrapper);
}
function searchTrackLeftclicked(trackId, trackName)
{
  playlistObj.enqueueLast(trackId, trackName);
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
  beautified = beautified.replace(/ HD$/i, "");
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
  beautified = beautified.replace(/^[-~.] /, "");
  return beautified;
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
  background-position: calc(100% - 10px) 0px;
  border-width: 4px;
  border-radius: 3px;
}
.search_list_wrapper {
}
.search_list_element {
  border-bottom: 2px solid #e0e0e0;
  margin: 0.4em 0em 0.4em 0em;
  padding: 0em 0em 0.2em 0.3em;
  overflow: hidden;
}
.search_context_wrapper {
  font-family: Sans, Sans-Serif, Arial;
  width: 8em;
  height: 8em;
  border: 3px solid #808080;
  border-radius: 10px;
  background-color: #ffffff;
  overflow: hidden;
}
.search_context_element {
  background-color: #f0f0f0;
  padding: 0.3em;
  margin: 0em 0em 0.2em 0em;
  border-radius: 10px;
}
.search_context_element:hover {
  background-color: #505050;
  color: #f0f0f0;
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
  background-color: #e8e8e8;
  /*margin: 0em 0.2em 0em 0.2em;*/
  padding: 0.1em;
  text-align: center;
  color: #000000;
  font-weight: bold;
  border-left:  3px solid #d0d0d0;
  border-right: 3px solid #d0d0d0;
}
.paging_button_first {
  display: block;
  margin-left: 0em;
  border-left: none;
  border-top-left-radius: 7px;
  border-bottom-left-radius: 7px;
}
.paging_button_last {
  display: block;
  border-right: none;
  border-top-right-radius: 7px;
  border-bottom-right-radius: 7px;
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
  height: 200px;
  overflow: auto;
}
.playlist_link:link, .playlist_link:visited {
  color: #000000;
  text-decoration: none;
}
.playlist_element {
  border-bottom: 2px solid #a0a0a0;
  background-color: #000000;
  color: #e0e0e0;
  overflow: hidden;
  padding: 0.15em 0em 0.15em 0.1em;
}
.playlist_selected_element {
  color: #000000;
  background-color: #ffffff;
  background-image: linear-gradient(to bottom, #ffffff 0%, #e8e8e8 85%, #a0a0a0 100%);
}
.playlist_selected_element::before {
  content: "▶ ";
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
<label for="search_input" class="search_label">Suche</label><br/>
<input type="text" id="search_input" class="search_input" size="20" />
<div id="search_list_wrapper" class="search_list_wrapper">
</div>
</div>
</div>
</body>
</html>
<?php


?>
