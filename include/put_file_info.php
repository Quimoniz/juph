<?php
//header('HTTP/1.1 303 See Other');
//header('Location: ?');
print_r($_POST);
print_r($_FILES);
echo "<br/>\n";
if(isset($_POST['id'])
&& isset($_POST['trackid'])
&& isset($_POST['trackname'])
&& isset($_POST['comment'])
&& isset($_POST['unsynchronised_lyric'])
&& isset($_POST['synchronised_lyric'])
&& isset($_POST['tag_ARTIST'])
&& isset($_POST['tag_ALBUM'])
&& isset($_POST['tag_GENRE'])
&& isset($_POST['tag_YEAR']))
{
    function string_maxlen($sourceString, $maxlen)
    {
        if($maxlen <= strlen($sourceString))
        {
            return substr($sourceString, 0, $maxlen);
        } else
        {
            return $sourceString;
        }
    }



    $paramId = intval($_POST['id']);
    $paramTrackid = intval($_POST['trackid']);
    $paramTrackname = string_maxlen($_POST['trackname'], 1000);
    $paramComment = string_maxlen($_POST['comment'], 4096);
    $paramUslt = string_maxlen($_POST['unsynchronised_lyric'], 32768);
    $paramSylt = string_maxlen($_POST['synchronised_lyric'], 32768);
    $paramTags = array();
    $paramTags["ARTIST"] = string_maxlen($_POST['tag_ARTIST'], 1024);
    $paramTags["ALBUM"] = string_maxlen($_POST['tag_ALBUM'], 1024);
    $paramTags["GENRE"] = string_maxlen($_POST['tag_GENRE'], 1024);
    $paramTags["YEAR"] = intval($_POST['tag_YEAR']);

    $textEncoding = 'UTF-8';
    require_once('lib/getid3/getid3/getid3.php');
    $getID3 = new getID3;
    $getID3->setOption(array('encoding'=>$textEncoding));
    require_once('lib/getid3/getid3/write.php');
    $tagwriter = new getid3_writetags;
    $result = @$dbcon->query('SELECT `path_str` FROM `filecache` WHERE `id`=' . $paramId);
    if(!(FALSE === $result))
    {
        $response_filename = $result->fetch_assoc();
        $tagwriter->filename = $CONFIG_VAR['MUSIC_DIR_ROOT'] . '/' . $response_filename['path_str'];
        $tagwriter->tagformats = array('id3v2.3');
        $tagwriter->overwrite_tags = true;
        $tagwriter->remove_other_tags = false;
        $tagwriter->tag_encoding = $textEncoding;
 
        $tagDataArray = array();
        if(0 != $paramTrackid)
        {
            $tagDataArray['track_number'] = array('' . $paramTrackid);
        }
        if(0 < strlen($paramTrackname))
        {
            $tagDataArray['title'] = array($paramTrackname);
        }
        if(0 < strlen($paramTags['ARTIST']))
        {
            $tagDataArray['artist'] = array($paramTags['ARTIST']);
        }
        if(0 < strlen($paramTags['ALBUM']))
        {
            $tagDataArray['ALBUM'] = array($paramTags['ALBUM']);
        }
        if(0 < strlen($paramTags['GENRE']))
        {
            $tagDataArray['GENRE'] = array($paramTags['GENRE']);
        }
        if(0 != $paramTags['YEAR'])
        {
            $tagDataArray['YEAR'] = array('' . $paramTags['YEAR']);
        }
        if(0 < strlen($paramComment))
        {
            $tagDataArray['comment'] = array($paramComment);
        }
        if(0 < strlen($paramUslt))
        {
            $tagDataArray['unsynchronised_lyric'] = array($paramUslt);
        }
        if(0 < strlen($paramSylt))
        {
            $tagDataArray['synchronised_lyric'] = array($paramSylt);
        }
        $tagwriter->tag_data = $tagDataArray;
        if($tagwriter->WriteTags())
        {
            echo 'Tags successfully written!';
            echo "<br/>\n";
            if(!empty($tagwriter->warnings))
            {
                foreach($tagwriter->warnings as $curWarning)
                {
                    echo $curWarning;
                    echo "<br/>\n";
                }
            }
        } else
        {
            echo "Could not write ID3 tags";
            echo "<br/>\n";
            foreach($tagwriter-> errors as $curError)
            {
                echo $curError;
                echo "<br/>\n";
            }
        }
        print_r($tagDataArray);
        @$dbcon->query('DELETE FROM `relation_tags` WHERE `fid`=' . $paramId);
        fileheader_tagification($dbcon, array( 0 => array('id' => $paramId, 'path_str' => $response_filename['path_str'])));
        directory_tagification($dbcon, array( 0 => array('id' => $paramId, 'path_str' => $response_filename['path_str'])));
    }
}
exit(0);
?>
