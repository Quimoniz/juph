<?php

class search_query_result {
    public $total_count = 0;
    public $result_count = 0;
    public $offset = 0;
    public $rows = array();
    public $success = true;
    public $search_query = '';
}

function query_db($search_str = NULL, $search_type = "all", $search_limit = 10, $search_offset = 0, $search_count_results = true, $sorting_keys = array("type" => "DESC", "count_played" => "DESC", "name" => "ASC"))
{
    global $dbcon;

    $search_str = $dbcon->real_escape_string($search_str);
    $result_set = new search_query_result();
    if($search_count_results)
    {
        $select_sql = '';
        if('all' == $search_type || 'file' == $search_type)
        {
            $select_sql .= 'SELECT COUNT(`filecache`.`id`) AS \'count_matches\' FROM `filecache` WHERE `filecache`.`valid`=\'Y\'';
            if(NULL != $search_str)
            {
                $select_sql .= 'AND (`filecache`.`path_filename` LIKE \'%' . $search_str . '%\' OR `filecache`.`id`=ANY(SELECT DISTINCT `relation_tags`.`fid` FROM `relation_tags` WHERE `relation_tags`.`tid`= ANY(SELECT `tags`.`id` FROM `tags` WHERE `tagname` LIKE \'%' . $search_str . '%\')))';
            }
        }
        if('all' == $search_type)
        {
            $select_sql .= ' UNION ';
        }
        if('all' == $search_type || 'playlist' == $search_type)
        {
            $select_sql .= 'SELECT COUNT(`id`) AS \'count_matches\' FROM `playlists`';
            if(NULL != $search_str)
            {
                $select_sql .= ' WHERE `name` LIKE \'%' . $search_str . '%\'';
            }
        }
        $result_matches = @$dbcon->query($select_sql);
        if(!(FALSE === $result_matches))
        {
            while($cur_row = $result_matches->fetch_assoc())
            {
                $result_set->total_count += (int) $cur_row['count_matches'];
            }
            
        }
    }
    $select_sql = '';
    if('all' == $search_type || 'file' == $search_type)
    {
        $select_sql .= 'SELECT `id`,`path_filename` AS \'name\', \'file\' AS \'type\',`count_played` AS \'count_played\', `length` AS \'length\' FROM `filecache` WHERE `valid`=\'Y\'';
        if(NULL != $search_str)
        {
            $select_sql .= ' AND (`filecache`.`path_filename` LIKE \'%' . $search_str . '%\' OR `filecache`.`id`=ANY(SELECT DISTINCT `relation_tags`.`fid` FROM `relation_tags` WHERE `relation_tags`.`tid`= ANY(SELECT `tags`.`id` FROM `tags` WHERE `tagname` LIKE \'%' . $search_str . '%\')))';
        }
    }
    if('all' == $search_type)
    {
        $select_sql = '(' . $select_sql . ') UNION (';
    }
    if('all' == $search_type || 'playlist' == $search_type)
    {
        $select_sql .= 'SELECT `id`, `name`, \'playlist\' AS \'type\', `count_played` AS \'count_played\', `length` AS \'length\' FROM `playlists`';
        if(NULL != $search_str)
        {
            $select_sql .= ' WHERE `name` LIKE \'%' . $search_str . '%\'';
        }
    }
    if('all' == $search_type)
    {
        $select_sql .= ')';
    }
    if($sorting_keys && 0 < count($sorting_keys))
    {
        $i = 0;
        $valid_rows = array('id', 'name', 'type', 'count_played', 'length');
        foreach($sorting_keys as $row_name => $ascending)
        {
            if(in_array($row_name, $valid_rows))
            {
                if(in_array($ascending, array('ASC', 'DESC')))
                {
                    if(0 == $i)
                    {
                        $select_sql .= ' ORDER BY';
                    } else
                    {
                        $select_sql .= ',';
                    }
                    $select_sql .= ' `' . $row_name . '` ' . $ascending;
                    $i++;
                }
            }
        }
    }
    if(NULL != $search_limit || NULL != $search_offset)
    {
        if(NULL == $search_limit || 0 > $search_limit)
        {
            // infinite, just some large number
            // as from documentation: https://dev.mysql.com/doc/refman/5.7/en/select.html
            $search_limit = '18446744073709551615';
        }
        if(NULL == $search_offset || 0 > $search_offset)
        {
            $search_offset = 0;
        } else
        {
            $result_set->offset = $search_offset;
        }
        $select_sql .= ' LIMIT ' . $search_offset . ',' . $search_limit;
    }
    $result_set->search_query = $select_sql;
    $result_matches = @$dbcon->query($select_sql);
    if(!(FALSE === $result_matches))
    {
        $select_sql = 'SELECT `relation_tags`.`fid` AS \'id\', GROUP_CONCAT(`tags`.`tagname`) AS \'tags\' FROM `relation_tags` INNER JOIN `tags` ON `relation_tags`.`tid`=`tags`.`id` WHERE ';
        $i = 0;
        $j = 0;
        $match_arr = array();
        while($cur_row = $result_matches->fetch_assoc())
        {
            $result_set->rows[] = array(
                'id' => $cur_row['id'],
                'name' => $cur_row['name'],
                'type' => $cur_row['type'],
                'count_played' => $cur_row['count_played'],
                'length' => $cur_row['length'],
                'tags' => ''
              );
            if('file' == $cur_row['type'])
            {
                if(0 < $j)
                {
                    $select_sql .= ' OR ';
                }
                $select_sql .= '`relation_tags`.`fid`=' . $cur_row['id'];
                $match_arr[(int) $cur_row['id']] = $i;
                $j++;
            }
            $i++;
        }
        $result_set->result_count = $i;
        if(0 < $j)
        {
            $select_sql .= ' GROUP BY `relation_tags`.`fid`';
            $tags_result = @$dbcon->query($select_sql);
            if(!(FALSE === $tags_result))
            {
                while($cur_row = $tags_result->fetch_assoc())
                {
                    $cur_id = (int) $cur_row['id'];
                    if(isset($match_arr[$cur_id]))
                    {
                        $result_set->rows[$match_arr[$cur_id]]['tags'] = $cur_row['tags'];
                    }
                }
            }
        }
    } else
    {
        $result_set->success = false;
    }
    return $result_set;
}

function print_result_json($result_obj)
{
    global $AJAX_PAGE_LIMIT;

    if($result_obj->success)
    {
        if(0 < $result_obj->total_count)
        {
            echo "{\n\"success\": true,\n\"countMatches\":";
            echo $result_obj->total_count . ",\n\"pageLimit\":" . $AJAX_PAGE_LIMIT;
            echo ",\n\"offsetMatches\":" . $result_obj->offset . ",\n\"matches\": [\n";
            $i = 0;
            foreach($result_obj->rows as $cur_key => $cur_arr)
            {
                if(0 < $i) echo ",\n";
                echo "{ \"id\": " . $cur_arr['id'];
                echo ", \"type\": \"" . $cur_arr['type'];
                echo "\", \"countPlayed\": " . $cur_arr['count_played'];
                echo ", \"length\": " . $cur_arr['length'];
                echo ", \"name\": \"" . js_escape($cur_arr['name']) . "\"";
                echo ", \"tags\": \"" . js_escape($cur_arr['tags']) . "\"}";
                $i++;
            }
            echo "]\n}";
        } else
        {
            client_error('no query results', true);
        }
    } else
    {
        server_error('database query failure', true);
    }
}

$perform_disconnect_trick = true;
//do not perform the premature disconnect trick if we
//  are dealing with a full-scale request for ALL of
//  a song. Since we must prebuffer the request for
//  the premature disconnect trick. Which would require
//  loading all of a file's contents in a buffer before
//  serving it.  We also don't want to do that, because
//  loading all of the file, and then having the browser
//  abort the download in the middle of transfer makes
//  us waste ressources unnecessarily. And that case
//  happens usually for _EVERY_ track above like 1-2
//  minutes in length.
//  Not buffering all the file's content also gives the
//  user a significant performance boost, e.g. a 1 hour
//  long song now starts to play after 2 seconds instead
//  of 6 seconds.
if(isset($_GET['request_track']) && !isset($_SERVER['HTTP_RANGE']))
{
    $perform_disconnect_trick = false;
}

if($perform_disconnect_trick)
{
    prepare_premature_disconnect();
}

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
        $results = query_db($search_subject, 'all' , $AJAX_PAGE_LIMIT, $search_offset, true);
        print_result_json($results);
    }
} else if(isset($_GET['request_track']))
{
    $target_id = (int) $_GET['request_track'];
    if(0 < $target_id && 9223372036854776000 > $target_id)
    {
        $result_raw = @$dbcon->query('SELECT `path_str`, `length`, `valid` FROM `filecache` WHERE `id`=' . $target_id . ' AND `valid`=\'Y\'');
        if(!(FALSE === $result_raw) && 0 < $result_raw->num_rows)
        {
            $result_row = $result_raw->fetch_assoc();
            $result_path = $CONFIG_VAR['MUSIC_DIR_ROOT'] . '/' . $result_row['path_str'];
            if('Y' == $result_row['valid'] && file_exists($result_path))
            {
                header('Content-Type: audio/mp3');
                header('Content-Disposition: inline; filename="' . str_replace(array("\"", "\\"), array("\\\"", "\\\\"), basename($result_row['path_str'])) . '"');
                header('Accept-Ranges: bytes');
                $file_duration = (int) $result_row['length'];
                if(0 != $file_duration)
                {
                    header('X-Content-Duration: ' . $file_duration);
                    header('Content-Duration: ' . $file_duration);
                }
                $file_start = 0;
                $file_end = -1;
                $file_filesize = filesize($result_path);
                $CHUNK_SIZE = 2000000;
                $MAX_FILE_TRANSFER = 20000000;
                if(isset($_SERVER['HTTP_RANGE']))
                {
                    preg_match('/bytes=([0-9]+)-([0-9]+)?/', $_SERVER['HTTP_RANGE'], $byte_matches);
                    if($byte_matches && 1 < count($byte_matches))
                    {
                        $file_start = (int) $byte_matches[1];
                        if(2 < count($byte_matches) && ((int)$byte_matches[2]) > $file_start)
                        {
                            $file_end = (int) $byte_matches[2];
                        }
                    }
                }
                if(0 != $file_start)
                {
                    if(-1 == $file_end)
                    {
                        if(($file_filesize - $file_start) > $MAX_FILE_TRANSFER)
                        {
                            $file_end = $file_start + $MAX_FILE_TRANSFER;
                        }
                    } else if(($file_end - $file_start) > $MAX_FILE_TRANSFER)
                    {
                        $file_end = $file_start + $MAX_FILE_TRANSFER;
                    }
                } else if($file_filesize > $MAX_FILE_TRANSFER)
                {
                    $file_end = $MAX_FILE_TRANSFER;
                }
                
                if(0 == $file_start)
                {
                    @$dbcon->query('UPDATE `filecache` SET `count_played`=`count_played`+1 WHERE `filecache`.`id`=' . $target_id);
                }

                if(0 != $file_start || -1 != $file_end)
                {
                    $file_handle = fopen($result_path, 'r');
                    if($file_start < $file_filesize)
                    {
                        if(0 < $file_start)
                        {
                            fseek($file_handle, $file_start);
                        } else {
                            $file_start = 0;
                        }
                        if(-1 == $file_end)
                        {
                            $file_end = $file_filesize;
                        }
                        header('HTTP/1.1 206 Partial Content');
                        header('Content-Range: bytes ' . $file_start . '-' . ($file_end - 1) . '/' . $file_filesize);
                        header('Content-Length: ' . ($file_end - $file_start + 1));
                        echo fread($file_handle, $file_end - $file_start);
                        fclose($file_handle);
                    } else
                    {
                        client_error('416 Requested range not satisfiable');
                    }
                } else {
                    // "If you just want to get the contents of a file into a string,
                    //  use file_get_contents() as it has much better performance
                    //  than the code above." - documentation from fread()
                    // Note by Quimoniz: HEY! if you read in a 1 hour song
                    //   it still takes over 5 seconds!
                    //   so just send chunks, until client aborts connection,
                    //   or all was served
                    //echo  file_get_contents($result_path);
                    $file_offset = 0;
                    $file_handle = fopen($result_path, 'r');
                    header('Content-Length: ' . $file_filesize);
                    while($file_offset < $file_filesize)
                    {
                        echo fread($file_handle, $CHUNK_SIZE);
                        $file_offset += $CHUNK_SIZE;
                    }
                    fclose($file_handle);
                }
            } else
            {
                server_error('Could not locate file');
            }
        } else
        {
            server_error('no such database entry');
        }
    } else
    {
        client_error('no');
    }
} else if(isset($_GET['file_info']))
{
    $file_id = (int) $_GET['file_info'];
    $result = @$dbcon->query('SELECT `path_str`, `size`, `count_played`, `tagified`, `length`, `bitrate`, `frequency`, `trackid`, `stereo`, `trackname`, `comment` FROM `filecache` WHERE `id`=' . $file_id);
    if(!(FALSE === $result))
    {
        $filecache_data = $result->fetch_assoc();
        $result = @$dbcon->query('SELECT `tagname`, `tagtype`, `description` FROM `tags` WHERE `id` = ANY(SELECT `tid` FROM `relation_tags` WHERE `fid`=' . $file_id . ')');
        $tag_data = array();
        if(!(FALSE === $result))
        {
            while($cur_row = $result->fetch_assoc())
            {
                $tag_data[] = $cur_row;
            }
        }
        require_once('lib/getid3/getid3/getid3.php');
        $getID3 = new getID3;
        $full_filepath = $CONFIG_VAR['MUSIC_DIR_ROOT'] . '/' . $filecache_data['path_str'];
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

        echo '{ "success": true, ';
        echo '"id": ' . $file_id . ', ';
        echo '"path_str": "' . js_escape($filecache_data['path_str']) . '", ';
        echo '"size": ' . $filecache_data['size'] . ', ';
        echo '"count_played": ' . $filecache_data['count_played'] . ', ';
        echo '"tagified": ' . ('Y' == $filecache_data['tagified'] ? 'true' : 'false') . ', ';
        echo '"length": ' . $filecache_data['length'] . ', ';
        echo '"bitrate": ' . $filecache_data['bitrate'] . ', ';
        echo '"frequency": ' . $filecache_data['frequency'] . ', ';
        echo '"trackid": ' . $filecache_data['trackid'] . ', ';
        echo '"stereo": "' . $filecache_data['stereo'] . '", ';
        echo '"trackname": "' . js_escape($filecache_data['trackname']) . '", ';
        echo '"comment": "' . js_escape($filecache_data['comment']) . '", ';
        if(isset($id3_fileinfo['comments']['unsynchronised_lyric']))
        {
            echo '"unsynchronised_lyric": "' . js_escape(implode("\n", $id3_fileinfo['comments']['unsynchronised_lyric'])) . '", ';
        }
        if(isset($id3_fileinfo['comments']['synchronised_lyric']))
        {
            echo '"synchronised_lyric": "' . js_escape(implode("\n", $id3_fileinfo['comments']['synchronised_lyric'])) . '", ';
        }
        if(isset($id3_fileinfo['id3v2']['chapters']))
        {
            echo '"chapters": [';
            $i = 0;
            foreach($id3_fileinfo['id3v2']['chapters'] as $cur_chapter)
            {
                if(0 < $i)
                {
                  echo ', ';
                }
                echo '{"begin": ' . intval($cur_chapter['time_begin']) . ',';
                echo '"end": ' . intval($cur_chapter['time_end']) . ',';
                echo '"name": "' . js_escape($cur_chapter['chapter_name']) . '"} ';
                $i++;
            }
            echo '],';
        }
        if($haz_picture)
        {
            echo '"picture": {';
            echo '"mime": "' . js_escape($picture_mime) . '", ';
            echo '"width": ' . $picture_dimensions[0] . ', ';
            echo '"height": ' . $picture_dimensions[1] . ', ';
            echo '"data": "' . base64_encode($picture_data) . '"}, ';
        }
        echo '"tags": [';
        $i = 0;
        foreach($tag_data as $cur_taginfo)
        {
            if(0 < $i)
            {
              echo ', ';
            }
            echo '{"name": "' . js_escape($cur_taginfo['tagname']) . '", '; 
            echo '"type": "' . js_escape($cur_taginfo['tagtype']) . '", '; 
            echo '"description": "' . js_escape($cur_taginfo['description']) . '" }'; 
            $i++;
        }
        echo ']}';
    } else
    {
        echo '{ "success": false }';
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
        $result = true;
        if(1 < $i)
        {
            $result = $dbcon->query($insert_sql);
        }
        $dbcon->query('UPDATE `playlists` SET `length`=(SELECT SUM(`length`) FROM `filecache` WHERE `id` = ANY(SELECT `fid` FROM `relation_playlists` WHERE `pid`=' . $playlist_db_id . ')) WHERE `id`=' . $playlist_db_id . ';');
        if(FALSE === $result)
        {
            server_error("Could not enter playlist items into playlist", true);
        } else
        {
            echo "{ \"success\": true }";
        }
    }
} else if(isset($_GET['request_session_playlist']))
{
    $param_session_id = $_GET['request_session_playlist'];
    //only allow alphanumeric characters
    $param_session_id = preg_replace("[^0-9A-Za-z]", "", $param_session_id);
    $result = @$dbcon->query('SELECT `filecache`.`id` AS \'id\', \'file\' AS \'type\', `filecache`.`path_str` AS \'name\' FROM `session_playlist` INNER JOIN `filecache` ON `session_playlist`.`fid`=`filecache`.`id` WHERE `session_playlist`.`session_id`=\'' . $param_session_id . '\' ORDER BY `session_playlist`.`prank` ASC');
    if(!(FALSE === $result))
    {
      echo "{\n\"success\": true,\n\"matches\": [";
      for($i = 0; $cur_row = $result->fetch_assoc(); $i++)
      {
        if(0 < $i) echo ",\n";
        echo "{ \"id\": " . $cur_row['id'] . ", \"type\": \"" . $cur_row['type'] . "\",\"name\": \"" . js_escape($cur_row['name']) . "\"}";
      }
      echo "]\n}";
    } else {
      server_error("Error when looking up playlist", false);
    }
} else if(isset($_GET['put_session_playlist']))
{
    $param_session_id = $_GET['put_session_playlist'];
    //only allow alphanumeric characters
    $param_session_id = preg_replace("[^0-9A-Za-z]", "", $param_session_id);
    if(isset($_POST['tracks']))
    {
        $arr_of_tracks = array();
        foreach(explode(',', $_POST['tracks']) as $curFid)
        {
            if(is_numeric($curFid))
            {
                $arr_of_tracks[] = (int) $curFid;
            }
        }

        $dbcon->query('DELETE FROM `session_playlist` WHERE `session_id`=\'' . $param_session_id . '\'');

        if(0 < count($arr_of_tracks))
        {
            $cur_rank = 1;
            $insert_sql = 'INSERT INTO `session_playlist` (`session_id`,`fid`,`prank`) VALUES';
            foreach($arr_of_tracks as $cur_track)
            {
                if(1 < $cur_rank)  $insert_sql .= ', ';
                $insert_sql .= '(\'' . $param_session_id . '\', ' . $cur_track . ', ' . $cur_rank . ')';
                $cur_rank++;
            }
            $result = true;
            if(1 < $cur_rank)
            {
                $result = $dbcon->query($insert_sql);
            }
            if(FALSE === $result)
            {
                server_error("Could not store session playlist", true);
            } else
            {
                echo "{ \"success\": true }";
            }
        } else
        {
            echo "{ \"success\": true }";
        }
    } else
    {
        client_error("Parameter tracks not set", true);
    }
} else if(isset($_GET['scan_music_dir']))
{
    scan_music_dir($CONFIG_VAR['MUSIC_DIR_ROOT'], $dbcon);
    echo "{ \"success\": true }";
} else if(isset($_GET['popular']))
{
    $popular_mode = $_GET['popular'];
    if(NULL == $popular_mode || '' == $popular_mode)
    {
        $popular_mode = 'brief_all';
    }
    $search_offset = 0;
    if(isset($_GET['matching_offset']))
    {
        $search_offset = (int) $_GET['matching_offset'];
        if(0 > $search_offset)
        {
            $search_offset = 0;
        }
    }
    if('brief_all' == $popular_mode)
    {
        $results_playlist = query_db(NULL, 'playlist', 5, 0, false);
        $results_file     = query_db(NULL, 'file'    , 5, 0, false);
        if($results_playlist->success || $results_file->success)
        {
            $results_all = new search_query_result();
            $results_all->total_count = $results_all->result_count = $results_playlist->result_count + $results_file->result_count;
            $results_all->rows = array_merge($results_playlist->rows, $results_file->rows);
            print_result_json($results_all);
        } else
        {
            server_error('could not look up popular tracks', true);
        }
    } else if('all' == $popular_mode)
    {
        print_result_json(query_db(NULL, 'all', $AJAX_PAGE_LIMIT, $search_offset, true));
    } else if('playlist' == $popular_mode)
    {
        print_result_json(query_db(NULL, 'playlist', $AJAX_PAGE_LIMIT, $search_offset, true));
    } else if('file' == $popular_mode)
    {
        print_result_json(query_db(NULL, 'file', $AJAX_PAGE_LIMIT, $search_offset, true));
    }
} else if(isset($_GET['newest']))
{
    $newest_mode = $_GET['newest'];
    $search_offset = 0;
    if(isset($_GET['matching_offset']))
    {
        $search_offset = (int) $_GET['matching_offset'];
        if(0 > $search_offset)
        {
            $search_offset = 0;
        }
    }
    $search_order = array('id' => 'DESC', 'type' => 'DESC', 'count_played' => 'DESC');
    if(in_array($newest_mode, array('playlist', 'file')))
    {
        print_result_json(query_db(NULL, $newest_mode, $AJAX_PAGE_LIMIT, $search_offset, true, $search_order));
    } else
    {
        print_result_json(query_db(NULL, 'all', $AJAX_PAGE_LIMIT, $search_offset, true, $search_order));
    }
}

if($perform_disconnect_trick)
{
    do_premature_disconnect();
    tagify_filecache($dbcon);
}
exit(0);
?>
