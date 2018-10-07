<?php
function gen_pwd($pwd_len = 15, $use_special_chars = true)
{
    $chr_grp = array(array(48,57),array(65,90),array(97,122));
    $chr_used_grp = array(0,0,0);
    if($use_special_chars)
    {
        $chr_grp[] = array(35,47);
        $chr_grp[] = array(58,63);
        $chr_grp[] = array(91,96);
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
function scan_music_dir($MUSIC_DIR_ROOT, $dbcon)
{
    global $cur_time;
    $scan_complete = False;
    $scan_filecount = 0;
    $scan_errormessage='';
    $i = 0; // total count of files

    if(is_dir($MUSIC_DIR_ROOT))
    {
        //scan algorithm
        $dir_handle = opendir($MUSIC_DIR_ROOT);
        $dir_names = array();
        $dir_names[] = $MUSIC_DIR_ROOT;
        $cur_dirbase = $MUSIC_DIR_ROOT . '/';
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
        $scan_errormessage .= 'Configured MUSIC_DIR_ROOT "' . $MUSIC_DIR_ROOT . '" not accessible as a directory.';
    }
    
    //invalidate all files in table filecache which do not have the timestamp of the current scan
    @$dbcon->query('UPDATE `filecache` SET `valid`=\'N\' WHERE `last_scan`!=' . $cur_time);
    //enter scan results into database (i.e. enter $cur_time, $scan_complete, $scan_filecount, $san_errormessage)
    @$dbcon->query('INSERT INTO `scans` (`time`, `completed`, `error_message`, `files_scanned`) VALUES (' . $cur_time . ', \'' . (0 < strlen($scan_errormessage) ? 'N' : 'Y') . '\', \'' . $dbcon->real_escape_string($scan_errormessage) . '\', ' . $i . ')');
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

?>