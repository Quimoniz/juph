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
    $dbcon->autocommit(FALSE);
    $dbcon->begin_transaction();
    $dbcon->query('CREATE TEMPORARY TABLE `tagification_table` AS SELECT `id`,`path_str` FROM `filecache` WHERE `tagified`=\'N\' ORDER BY `id` ASC LIMIT 50'); /* TODO: make this limit '50' configurable somewhere somehow */
    $dbcon->query('UPDATE `filecache` SET `tagified`=\'Y\' WHERE `id`=ANY(SELECT `id` FROM `tagification_table`)');
    $untagified_result = $dbcon->query('SELECT * FROM `tagification_table`');
    $dbcon->commit();
    $dbcon->autocommit(TRUE);
    //$untagified_result = $dbcon->query('SELECT `id`,`path_str` FROM `filecache` WHERE `tagified`=\'N\' ORDER BY `id` ASC LIMIT 100');
    if(!(FALSE === $untagified_result) && 0 < $untagified_result->num_rows)
    {
        $untagified_files = array();
        while($cur_row = $untagified_result->fetch_row())
        {
            $untagified_files[] = array('id' => $cur_row[0], 'path_str' => $cur_row[1]);
        }
        directory_tagification($dbcon, $untagified_files);
        fileheader_tagification($dbcon, $untagified_files);
    }
}
function fileheader_tagification($dbcon, $untagified)
{
    global $CONFIG_VAR;
    $cur_row = false;
    $i = 0;
    $cur_filename;
    $tag_names_array = array('ALBUM' => array(),
                             'ARTIST' => array(),
                             'GENRE' => array(),
                             'YEAR' => array(),
                             'CODEC' => array(),
                             'FORMAT' => array());
    $tag_associations_array = array('ALBUM' => array(),
                                    'ARTIST' => array(),
                                    'GENRE' => array(),
                                    'YEAR' => array(),
                                    'CODEC' => array(),
                                    'FORMAT' => array());
    $filecache_updates = array();
    require_once('lib/getid3/getid3/getid3.php');
    $getID3 = new getID3;
    foreach($untagified as $cur_row)
    {
        $cur_filepath = $CONFIG_VAR['MUSIC_DIR_ROOT'] . '/' . $cur_row['path_str'];
        $i++;
        $cur_id = $cur_row['id'];
        $cur_fileinfo = $getID3->analyze($cur_filepath);
        getid3_lib::CopyTagsToComments($cur_fileinfo);
        if(!isset($cur_fileinfo['mime_type']))
        {
            continue;
        }
        $cur_mimetypes = explode('/', $cur_fileinfo['mime_type']);
        if(0 != strcmp('audio', $cur_mimetypes[0])
        && 0 != strcmp('video', $cur_mimetypes[0]))
        {
            continue;
        }
        
        /* TODO: assign to variables and execute these */
        $filecache_updates[] = generate_file_updates($dbcon, $cur_id, $cur_fileinfo);
        $found_tags = find_out_new_tag_associations($cur_fileinfo);
        foreach($found_tags as $tag_key => $tag_value)
        {
           if(!isset($tag_associations_array[$tag_key][$tag_value]))
           {
               $tag_associations_array[$tag_key][$tag_value] = array();
               $tag_names_array[$tag_key][$tag_value] = TRUE;
           }
           $tag_associations_array[$tag_key][$tag_value][] = $cur_id;
        }
    }
    foreach($filecache_updates as $cur_update)
    {
        $dbcon->query($cur_update);
    }
    $dbcon->commit();
    /* handle tag_names_array and tag_associations_array */
    /* first enter the new tags using INSERT IGNORE */
    $tag_inserts_sql = 'INSERT IGNORE INTO `tags` (`tagname`, `tagtype`, `description`) VALUES ';
    $tag_inserts_count = 0;
    $select_tag_id_sql = 'SELECT `id`, `tagname`, `tagtype` FROM `tags` WHERE ';
    foreach($tag_names_array as $cur_tag_type => $cur_tag_values)
    {
        foreach($cur_tag_values as $cur_tag_name => $dummy)
        {
            if(0 < $tag_inserts_count)
            {
                $tag_inserts_sql .= ', ';
                $select_tag_id_sql .= 'OR ';
            }
            $tag_inserts_sql .= '(\'' . $cur_tag_name . '\', \'' . $cur_tag_type . '\', \'Automatically generated from media tag\')';
            $select_tag_id_sql .= '(`tagname`=\'' . $cur_tag_name . '\' AND `tagtype`=\'' . $cur_tag_type . '\') ';
            $tag_inserts_count++;
        }
    }
    $tag_ids_result = FALSE;
    if(0 < $tag_inserts_count)
    {
        $dbcon->query($tag_inserts_sql);
        $dbcon->commit();
        $tag_ids_result = $dbcon->query($select_tag_id_sql);
    }
    /* iterate over $tag_ids_result and assign tag ids to $tag_names_array[tagtype][tagname] = $ID
     * for use in the following step 'second' */
    if($tag_ids_result)
    {
        $cur_row = FALSE;
        while(NULL !== ($cur_row = $tag_ids_result->fetch_assoc()))
        {
            $tag_names_array[$cur_row['tagtype']][$cur_row['tagname']] = $cur_row['id'];
        }
    }

    /* second enter the tag to file associations into table relation_tags */
    $relations_sql = 'INSERT IGNORE INTO `relation_tags` (`fid`,`tid`) VALUES ';
    $relations_count = 0;
    foreach($tag_associations_array as $cur_tagtype => $name_array)
    {
        foreach($name_array as $cur_tagname => $file_id_array)
        {
            foreach($file_id_array as $cur_fid)
            {
                $cur_tid = $tag_names_array[$cur_tagtype][$cur_tagname];
                if(0 < $relations_count)
                {
                    $relations_sql .= ', ';
                }
                $relations_sql .= '(' . $cur_fid . ',' . $cur_tid . ')';
                $relations_count++;
            }
        }
    }
    if(0 < $relations_count)
    {
        $dbcon->query($relations_sql);
        $dbcon->commit();
    }
}
function generate_file_updates($dbcon, $file_id, $file_info)
{
    $fields = array('comment' => '',
                    'trackid' => 0,
                    'trackname' => '',
                    'stereo' => 'UNKNOWN',
                    'bitrate' => 0,
                    'frequency' => 0,
                    'length' => 0);
    if(isset($file_info['audio']['channels']))
    {
        if(1 == $file_info['audio']['channels'])
        {
            $fields['stereo'] = 'MONO';
        } elseif(2 <= $file_info['audio']['channels'])
        {
            $fields['stereo'] = 'STEREO';
        }
    }
    if(isset($file_info['bitrate']))
    {
        $fields['bitrate'] = intval($file_info['bitrate']);
    }
    if(isset($file_info['audio']['sample_rate']))
    {
        $fields['frequency'] = intval($file_info['audio']['sample_rate']);
    }
    if(isset($file_info['playtime_seconds']))
    {
        $fields['length'] = round(floatval($file_info['playtime_seconds']));
    }
    if(isset($file_info['comments']['comment']))
    {
        if(is_array($file_info['comments']['comment']))
        {
            $fields['comment'] = implode(',', $file_info['comments']['comment']);
        } elseif(0 < strlen($file_info['comments']['comment']))
        {
            $fields['comment'] = $file_info['comments']['comment'];
        }
    }
    if(isset($file_info['comments']['track']))
    {
        $fields['trackid'] = intval(implode(' ', $file_info['comments']['track']));
    } elseif(isset($file_info['comments']['track_number']))
    {
        $fields['trackid'] = intval(implode(' ', $file_info['comments']['track_number']));
    }
    if(isset($file_info['comments']['title']))
    {
        if(is_array($file_info['comments']['title']))
        {
            $fields['trackname'] = implode(',', $file_info['comments']['title']);
        } elseif(0 < strlen($file_info['comments']['title']))
        {
            $fields['trackname'] = $file_info['comments']['title'];
        }
    }
    return 'UPDATE `filecache` SET `length`=' . $fields['length']
             . ', `bitrate`=' . $fields['bitrate']
             . ', `frequency`=' . $fields['frequency']
             . ', `trackid`=' . $fields['trackid']
             . ', `stereo`=\''. $fields['stereo']
             . '\', `trackname`=\'' . $dbcon->real_escape_string($fields['trackname'])
             . '\',`comment`=\'' . $dbcon->real_escape_string($fields['comment']) . '\' WHERE `id`=' . $file_id . '; ';
}
function find_out_new_tag_associations($file_info)
{
    $tag_associations = array();
    if(isset($file_info['comments']['album'][0]))
    {
        $tag_associations['ALBUM'] = $file_info['comments']['album'][0];
    }
    if(isset($file_info['comments']['artist'][0]))
    {
        $tag_associations['ARTIST'] = $file_info['comments']['artist'][0];
    }
    if(isset($file_info['comments']['genre'][0]))
    {
        $tag_associations['GENRE'] = $file_info['comments']['genre'][0];
    }
    if(isset($file_info['comments']['year'][0]))
    {
        $tag_associations['YEAR'] = $file_info['comments']['year'][0];
    }
    if(isset($file_info['audio']['codec']))
    {
        $tag_associations['CODEC'] = $file_info['audio']['codec'];
    }
    if(isset($file_info['fileformat']))
    {
        $tag_associations['FORMAT'] = $file_info['fileformat'];
    }
    return $tag_associations;
}
function directory_tagification($dbcon, $untagified)
{
    $cur_row = false;
    $cur_dir_arr = false;
    $cur_dir_name = "";
    $cur_id = -1;
    $ignore_levels = 1;
    // do two rounds,
    // first round, gather all the different tags
    //   -> enter them into table `tags`
    // second round, compile an insert for file<->tag relation
    $arr_tags = array();
    foreach($untagified as $cur_row)
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
    $insert_sql = 'INSERT IGNORE INTO `tags` (`tagname`, `tagtype`, `description`) VALUES';
    $select_sql = 'SELECT `id`, `tagname` FROM `tags` WHERE `tagtype`=\'DIRECTORY\' AND (';
    $i = 0;
    foreach($arr_tags as $cur_key => $cur_value)
    {
        if(0 < $i)
        {
            $insert_sql .= ', ';
            $select_sql .= ' OR ';
        }
        $insert_sql .= '(\'' . $dbcon->real_escape_string($cur_key) . '\', \'DIRECTORY\', \'from folder ' . $dbcon->real_escape_string($cur_key) . '\')';
        $select_sql .= '`tagname`=\'' . $dbcon->real_escape_string($cur_key) . '\'';
        $i++;
    }
    $select_sql .= ')';
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
       
        $insert_sql = 'INSERT IGNORE INTO `relation_tags` (`fid`,`tid`) VALUES';
        $j = 0;
        $k = 0;
        foreach($untagified as $cur_row)
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
            $k++;
        }
        $dbcon->query($insert_sql);
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
    return str_replace(array("\\", "\"","\n","\r"), array("\\\\", "\\\"", "\\n", "\\r"), $source_str);
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


function file_info($file_id, $analyze_mp3)
{
    global $CONFIG_VAR, $dbcon;
    $result = @$dbcon->query('SELECT `path_str`, `size`, `count_played`, `tagified`, `length`, `bitrate`, `frequency`, `trackid`, `stereo`, `trackname`, `comment` FROM `filecache` WHERE `id`=' . $file_id);
    if(!(FALSE === $result))
    {
        $file_data = $result->fetch_assoc();
        $result = @$dbcon->query('SELECT `tagname`, `tagtype`, `description` FROM `tags` WHERE `id` = ANY(SELECT `tid` FROM `relation_tags` WHERE `fid`=' . $file_id . ')');
        $file_data['tags'] = array();
        if(!(FALSE === $result))
        {
            while($cur_row = $result->fetch_assoc())
            {
                $file_data['tags'][] = $cur_row;
            }
        }
        if($analyze_mp3)
        {
            require_once('lib/getid3/getid3/getid3.php');
            $getID3 = new getID3;
            $full_filepath = $CONFIG_VAR['MUSIC_DIR_ROOT'] . '/' . $file_data['path_str'];
            $id3_fileinfo = $getID3->analyze($full_filepath);
            getid3_lib::CopyTagsToComments($id3_fileinfo);
            $haz_picture = FALSE;
            $picture_data = '';
            $picture_mime = '';
            $picture_dimensions = array( 0, 0);
            
            if(isset($id3_fileinfo['comments']['picture'][0]['data']))
            {
                $picture_data = $id3_fileinfo['comments']['picture'][0]['data'];
                $haz_picture = TRUE;
            }
            if(isset($id3_fileinfo['comments']['picture'][0]['image_mime']))
            {
                $picture_mime = $id3_fileinfo['comments']['picture'][0]['image_mime'];
            }
            if(isset($id3_fileinfo['comments']['picture'][0]['image_width'])
            && isset($id3_fileinfo['comments']['picture'][0]['image_height']))
            {
                $picture_dimensions[0] = intval($id3_fileinfo['comments']['picture'][0]['image_width']);
                $picture_dimensions[1] = intval($id3_fileinfo['comments']['picture'][0]['image_height']);
            }
            if($haz_picture)
            {
                $file_data['picture'] = array(
                    'mime' => $picture_mime,
                    'dimensions' => $picture_dimensions,
                    'data' => $picture_data);
            }
            if(isset($id3_fileinfo['comments']['unsynchronised_lyric']))
            {
                $file_data['unsynchronised_lyric'] = implode("\n", $id3_fileinfo['comments']['unsynchronised_lyric']);
            }
            if(isset($id3_fileinfo['comments']['synchronised_lyric']))
            {
                $file_data['synchronised_lyric'] = implode("\n", $id3_fileinfo['comments']['synchronised_lyric']);
            }
            if(isset($id3_fileinfo['id3v2']['chapters']))
            {
                $file_data['chapters'] = array(); 
                foreach($id3_fileinfo['id3v2']['chapters'] as $cur_chapter)
                {
                    $cur_element = array(
                        'begin' => intval($cur_chapter['time_begin']),
                        'end' => intval($cur_chapter['time_end']), 
                        'name' => $cur_chapter['chapter_name']);
                    $file_data['chapters'][] = $cur_element;
                }
            }
        }
        return $file_data;
    }
    return NULL;
}


?>
