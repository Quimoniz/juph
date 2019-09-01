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
    $dbcon->query('CREATE TEMPORARY TABLE `tagification_table` AS SELECT `id`,`path_str` FROM `filecache` WHERE `tagified`=\'N\' ORDER BY `id` ASC LIMIT 10'); /* TODO: make this limit '10' configurable somewhere somehow */
    $dbcon->query('UPDATE `filecache` SET `tagified`=\'Y\' WHERE `id`=ANY(SELECT `id` FROM `tagification_table`)');
    $untagified_result = $dbcon->query('SELECT * FROM `tagification_table`');
    $dbcon->commit();
    $dbcon->autocommit(TRUE);
    //$untagified_result = $dbcon->query('SELECT `id`,`path_str` FROM `filecache` WHERE `tagified`=\'N\' ORDER BY `id` ASC LIMIT 100');
    if(!(FALSE === $untagified_result) && 0 < $untagified_result->num_rows)
    {
        directory_tagification($dbcon, $untagified_result);
        $untagified_result->data_seek(0);
        fileheader_tagification($dbcon, $untagified_result);
    }
}
function fileheader_tagification($dbcon, $untagified)
{
    global $CONFIG_VAR;
    $cur_row = false;
    $i = 0;
    $filename_string = "";
    while($cur_row = $untagified->fetch_assoc())
    {
        $filename_string .= $CONFIG_VAR['MUSIC_DIR_ROOT'] . '/' . $cur_row['path_str'];
        $filename_string .= '\\0';
        $i++;
    }
    $music_dir_root_strlen = strlen($CONFIG_VAR['MUSIC_DIR_ROOT'] . '/');
    //$untagified->data_seek(0);
    //xargs -0 /usr/share/mplayer/midentify.sh 2>&1 | 
    $midentify_cmdline = '/bin/bash -c "/usr/bin/printf \\"' . $filename_string . '\\" | xargs -0 /usr/share/mplayer/midentify.sh 2>&1 > audio-report.txt"';
    exec($midentify_cmdline);
file_put_contents('midentify_parameters.txt', $midentify_cmdline);
    $audio_headers = file_get_contents('audio-report.txt');
file_put_contents('audio_headers_variable.txt', $audio_headers);
    $untagified->data_seek(0);
    $filecache_updates = '';
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
    $blob_offset = 0;
    $cur_chunk_pos = 0;
    $next_chunk_pos = strpos($audio_headers, "ID_AUDIO_ID=0\n", 0);
    /* go through the raw output of mplayer's midentify and extract
     * the chunks of information pertaining to each file */
$filename_comparison_str = '';
    while(FALSE !== $next_chunk_pos && $cur_row = $untagified->fetch_assoc())
    {
        $cur_id = $cur_row['id'];
        $cur_filepath = $cur_row['path_str'];
        $cur_chunk_pos = $next_chunk_pos;
        if($cur_chunk_pos)
        {
            $blob_offset = $cur_chunk_pos;
        }
        $next_chunk_pos = strpos($audio_headers, "\nID_AUDIO_ID=0\n", $blob_offset + 13);
        $cur_chunk_str = "";
        if($next_chunk_pos)
        {
            $cur_chunk_str = substr($audio_headers, $cur_chunk_pos, $next_chunk_pos - $cur_chunk_pos);
        } else
        {
            $cur_chunk_str = substr($audio_headers, $cur_chunk_pos);
        }
        $cur_filename_pos = strpos($cur_chunk_str, "\nID_FILENAME=");
        $extracted_filename = FALSE;
        if($cur_filename_pos)
        {
            $next_newline = strpos($cur_chunk_str, "\n", $cur_filename_pos + 13);
            if($next_newline)
            {
                $extracted_filename = substr($cur_chunk_str, $cur_filename_pos + 13, $next_newline - $cur_filename_pos - 13);
                $extracted_filename = str_replace("\\", "", $extracted_filename);
                $extracted_filename = substr($extracted_filename, $music_dir_root_strlen);
            }
        }
$filename_comparison_str .= $cur_filepath . '==' . $extracted_filename;
$filename_comparison_str .= "\n"; 
        if(TRUE) //$extracted_filename && 0 == strcmp($cur_filepath, $extracted_filename))
        {
            /* TODO: assign to variables and execute these */
            $filecache_updates .= generate_file_updates($dbcon, $cur_id, $cur_filepath, $cur_chunk_str);
            $found_tags = find_out_new_tag_associations($cur_chunk_str);
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
    }
file_put_contents('filename_comparison_str.txt', $filename_comparison_str);
file_put_contents('sql_filecache_updates.txt', $filecache_updates);
    $result_update = $dbcon->query($filecache_updates);
    $dbcon->commit();
file_put_contents('update_response.txt', print_r($result_update, TRUE));
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
function generate_file_updates($dbcon, $file_id, $file_filepath, $mplayer_media_identification)
{
    $fields = array('comment' => '',
                    'trackid' => 0,
                    'trackname' => '',
                    'stereo' => 'UNKNOWN',
                    'bitrate' => 0,
                    'frequency' => 0,
                    'length' => 0);
    if(strpos($mplayer_media_identification, "\nID_AUDIO_NCH=1"))
    {
        $fields['stereo'] = 'MONO';
    } else
    {
        $fields['stereo'] = 'STEREO';
    }
    $fields['bitrate'] = intval(extract_field_value($mplayer_media_identification, "ID_AUDIO_BITRATE", FALSE));
    $fields['frequency'] = intval(extract_field_value($mplayer_media_identification, "ID_AUDIO_RATE", FALSE));
    $fields['length'] = round(floatval(extract_field_value($mplayer_media_identification, "ID_LENGTH", FALSE)) * 1000);
    $clipinfo_array = extract_clipinfo_array($mplayer_media_identification);
    if(isset($clipinfo_array['comment']))
    {
        $fields['comment'] = $clipinfo_array['comment'];
    }
    if(isset($clipinfo_array['track']))
    {
        $fields['trackid'] = intval($clipinfo_array['track']);
    }
    if(isset($clipinfo_array['title']))
    {
        $fields['trackname'] = $clipinfo_array['title'];
    }
    return 'UPDATE `filecache` SET `length`=' . $fields['length']
             . ', `bitrate`=' . $fields['bitrate']
             . ', `frequency`=' . $fields['frequency']
             . ', `trackid`=' . $fields['trackid']
             . ', `stereo`=\''. $fields['stereo']
             . '\', `trackname`=\'' . $dbcon->real_escape_string($fields['trackname'])
             . '\',`comment`=\'' . $dbcon->real_escape_string($fields['comment']) . '\' WHERE `id`=' . $file_id . '; ';
}
function extract_clipinfo_array($mplayer_media_identification)
{
    $clipinfo_array = array();
    $cur_offset = 1;
    for($cur_index = 0; $cur_offset; $cur_index++)
    {
        $cur_pos = strpos($mplayer_media_identification, "\nID_CLIP_INFO_NAME" . $cur_index . "=", $cur_offset);
        if($cur_pos) {
            $cur_offset = $cur_pos + strlen("\nID_CLIP_INFO_NAME" . $cur_index . "=");

            $next_newline = strpos($mplayer_media_identification, "\n", $cur_offset);
            $cur_field_name = "";
            if($next_newline)
            {
                $cur_field_name = substr($mplayer_media_identification, $cur_offset, $next_newline - $cur_offset - 1);
                $cur_offset = $next_newline;
            } else
            {
                $cur_field_name = substr($mplayer_media_identification, $cur_offset);
            }
            $cur_pos = strpos($mplayer_media_identification, "\nID_CLIP_INFO_VALUE" . $cur_index . "=", $cur_offset);
            if($cur_pos)
            {
                $cur_offset = $cur_pos + strlen("\nID_CLIP_INFO_VALUE" . $cur_index . "=");

                $next_newline = strpos($mplayer_media_identification, "\n", $cur_offset);
                $cur_field_value = "";
                if($next_newline)
                {
                    $cur_field_value = substr($mplayer_media_identification, $cur_offset, $next_newline - $cur_offset - 1);
                } else
                {
                    $cur_field_value = substr($mplayer_media_identification, $cur_offset);
                }
                if($cur_field_name && $cur_field_value)
                {
                    $clipinfo_array[strtolower($cur_field_name)] = str_replace(array("\\n", "\\r", "\\t", "\\"), array("\n", "\r", "\t", ""), $cur_field_value);
                }
            }
        } else
        {
            $cur_offset = FALSE;
        }
    }
    return $clipinfo_array;
}
function extract_field_value($search_blob, $field_name, $do_unescape = FALSE)
{
    $field_pos = strpos($search_blob, "\n" . $field_name . "=");
    if($field_pos)
    {
        $field_end_pos = $field_pos + 1 + strlen($field_name) + 1;
        $next_newline = strpos($search_blob, "\n", $field_end_pos);
        $extracted_value = FALSE;
        if($next_newline)
        {
            $extracted_value = substr($search_blob, $field_end_pos, $next_newline - $field_end_pos);
        } else
        {
            $extracted_value = substr($search_blob, $field_end_pos);
        }
        if($do_unescape)
        {
            $extracted_value = str_replace(array("\\n", "\\r", "\\t", "\\"), array("\n", "\r", "\t", ""), $extracted_value);
        }

        return $extracted_value;
    }
    return FALSE;
}
function find_out_new_tag_associations($mplayer_media_identification)
{
    $clipinfo_array = extract_clipinfo_array($mplayer_media_identification);
    $tag_associations = array();
    if(isset($clipinfo_array['album']))
    {
        $tag_associations['ALBUM'] = $clipinfo_array['album'];
    }
    if(isset($clipinfo_array['artist']))
    {
        $tag_associations['ARTIST'] = $clipinfo_array['artist'];
    }
    if(isset($clipinfo_array['genre']))
    {
        $tag_associations['GENRE'] = $clipinfo_array['genre'];
    }
    if(isset($clipinfo_array['year']))
    {
        $tag_associations['YEAR'] = $clipinfo_array['year'];
    }
    $codec = extract_field_value($mplayer_media_identification, 'ID_AUDIO_CODEC', FALSE);
    if($codec)
    {
        $tag_associations['CODEC'] = $codec;
    }
    $format = extract_field_value($mplayer_media_identification, 'ID_AUDIO_FORMAT', FALSE);
    if($format)
    {
        $tag_associations['FORMAT'] = $format;
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
    while($cur_row = $untagified->fetch_assoc())
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
        $untagified->data_seek(0);
       
        $insert_sql = 'INSERT IGNORE INTO `relation_tags` (`fid`,`tid`) VALUES';
        $j = 0;
        $k = 0;
        while($cur_row = $untagified->fetch_assoc())
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
