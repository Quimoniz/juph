<?php
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
        $result_raw = @$dbcon->query('SELECT `path_str`, `valid` FROM `filecache` WHERE `id`=' . $target_id . ' AND `valid`=\'Y\'');
        if(!(FALSE === $result_raw) && 0 < $result_raw->num_rows)
        {
            $result_row = $result_raw->fetch_assoc();
            $result_path = $CONFIG_VAR['MUSIC_DIR_ROOT'] . '/' . $result_row['path_str'];
            if('Y' == $result_row['valid'] && file_exists($result_path))
            {
                header('Content-Type: audio/mp3');
                header('Content-Disposition: inline; filename="' . str_replace(array("\"", "\\"), array("\\\"", "\\\\"), basename($result_row['path_str'])) . '"');
                header('Accept-Ranges: bytes');
                $file_start = 0;
                $file_end = -1;
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
                
                if(0 != $file_start || -1 != $file_end)
                {
                    $file_handle = fopen($result_path, 'r');
                    $file_filesize = filesize($result_path);
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
                    echo  file_get_contents($result_path);
                }
                if(0 == $file_start)
                {
                    @$dbcon->query('UPDATE `filecache` SET `count_played`=`count_played`+1 WHERE `filecache`.`id`=' . $target_id);
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
    $result_matches = @$dbcon->query('(SELECT `id`,`path_str` AS \'name\', \'file\' AS \'type\',`count_played` AS \'count_played\' FROM `filecache` WHERE `valid`=\'Y\' ORDER BY `count_played` DESC LIMIT 5) UNION (SELECT `id`, `name`, \'playlist\' AS \'type\', `count_played` AS \'count_played\' FROM `playlists` ORDER BY `count_played` DESC LIMIT 5) ORDER BY `type` DESC, `count_played` DESC LIMIT 10');
    if(!(FALSE === $result_matches))
    {
        $count_matches = $result_matches->num_rows;
        $tag_arr = array();
        $select_sql = 'SELECT `relation_tags`.`fid` AS \'id\', GROUP_CONCAT(`tagname` SEPARATOR \',\') AS \'tags\' FROM `tags` INNER JOIN `relation_tags` ON `relation_tags`.`tid`=`tags`.`id` WHERE ';
        $i = 0;
        while($cur_row = $result_matches->fetch_assoc())
        {
            if('file' == $cur_row['type'])
            {
                $tag_arr[$cur_row['id']] = "";
                if(0 < $i)  $select_sql .= ' OR ';
                $select_sql .= '`relation_tags`.`fid`=' . $cur_row['id'];
                $i++;
            }
        }
        if(0 < $i)
        {
            $select_sql .= ' GROUP BY `relation_tags`.`fid`';
            $result_tags = $dbcon->query($select_sql);
            while($cur_row = $result_tags->fetch_assoc())
            {
                $tag_arr[$cur_row['id']] = $cur_row['tags'];
            }
        }
        $result_matches->data_seek(0);
        echo "{\n\"success\": true,\n\"countMatches\":";
        echo $count_matches . ",\n\"pageLimit\": 10";
        echo ",\n\"offsetMatches\": 0,\n\"matches\": [\n";
        $i = 0;
        for($i = 0; $cur_row = $result_matches->fetch_assoc(); $i++)
        {
            if(0 < $i) echo ",\n";
            echo "{ \"id\": " . $cur_row['id'] . ", \"type\": \"" . $cur_row['type'];
            echo "\",\"countPlayed\": " . $cur_row['count_played'];
            echo ",\"name\": \"" . js_escape($cur_row['name']) . "\"";
            echo ",\"tags\": \"";
            if('file' == $cur_row['type'] && isset($tag_arr[$cur_row['id']]))
            {
                echo js_escape($tag_arr[$cur_row['id']]);
            }
            echo "\"}";
        }
        echo "]\n}";
    } else
    {
        server_error('could not look up popular tracks', true);
    }
}

do_premature_disconnect();
tagify_filecache($dbcon);
exit(0);
?>
