<?php

if(isset($_GET['file_edit_form']))
{
    $file_id = (int) $_GET['file_edit_form'];
    $file_data = file_info($file_id, TRUE);
?>
<!DOCTYPE html5>
<html>
<head>
<meta charset="utf8" />
<title>juph file editor</title>
<style type="text/css">
<?php require_once('include/main_css.css'); ?>
</style>
<body>
<form method="POST" action="?put_file_info" enctype="multipart/form-data">
<h2 class="editmenu_heading">File specific data</h2>
<input type="hidden" name="id" value=<?php echo '"' . $file_id . '"'; ?> />
<div class="editmenu_wrapper_label"><label>ID in database</label></div>
<div class="editmenu_wrapper_input">
  <?php echo $file_id;  ?>
</div>
<div class="editmenu_wrapper_label"><label>Full Filepath</label></div>
<div class="editmenu_wrapper_input">
  <?php echo htmlspecialchars($file_data['path_str']);  ?>
</div>
<div class="editmenu_wrapper_label"><label>Size in Bytes</label></div>
<div class="editmenu_wrapper_input">
  <?php echo $file_data['size'];  ?>
</div>
<div class="editmenu_wrapper_label"><label>Count Played</label></div>
<div class="editmenu_wrapper_input">
  <?php echo $file_data['count_played'];  ?>
</div>
<div class="editmenu_wrapper_label"><label>Scanned for Tags</label></div>
<div class="editmenu_wrapper_input">
  <?php echo $file_data['tagified'];  ?>
</div>
<div class="editmenu_wrapper_label"><label>Duration</label></div>
<div class="editmenu_wrapper_input">
  <?php echo $file_data['length'];  ?>
</div>
<div class="editmenu_wrapper_label"><label>Bits per second</label></div>
<div class="editmenu_wrapper_input">
  <?php echo $file_data['bitrate'];  ?>
</div>
<div class="editmenu_wrapper_label"><label>Record frequency</label></div>
<div class="editmenu_wrapper_input">
  <?php echo $file_data['frequency'];  ?>
</div>
<div class="editmenu_wrapper_label"><label>Stereo</label></div>
<div class="editmenu_wrapper_input">
  <?php echo $file_data['stereo'];  ?>
</div>
<div class="editmenu_wrapper_label editmenu_wrapper_imageheight"><label>Picture</label></div>
<div class="editmenu_wrapper_input editmenu_wrapper_imageheight">
  <img src=<?php
if(isset($file_data['picture']))
{
    echo '"data:' . $file_data['picture']['mime'] . ';base64,';
    echo base64_encode($file_data['picture']['data']);
    echo '"';
} else
{
    echo '""';
}
 ?> id="upload_picture" width="200" height="200" />
  <br/>
<script type="text/javascript">
function picture_changed(inputEle) {
  document.getElementById('upload_picture').setAttribute('src', window.URL.createObjectURL(inputEle.files[0]));
}
</script>
  <input type="file" accept="image/*" multiple="false" onchange="picture_changed(this);"/>
</div>
<div class="editmenu_wrapper_label"><label for="trackid">Track number</label></div>
<div class="editmenu_wrapper_input">
  <input type="number" name="trackid" value=<?php echo '"' . $file_data['trackid'] . '"';  ?> />
</div>
<div class="editmenu_wrapper_label"><label for="trackname">Track name</label></div>
<div class="editmenu_wrapper_input">
  <input type="text" name="trackname" value=<?php echo '"' . str_replace("\"", "\\\"", $file_data['trackname']) . '"';  ?> />
</div>
<div class="editmenu_wrapper_label editmenu_wrapper_textareaheight"><label for="comment">Commentary</label></div>
<div class="editmenu_wrapper_input editmenu_wrapper_textareaheight">
  <textarea name="comment" rows="5" cols="60"><?php if(isset($file_data['comment'])) { echo htmlspecialchars($file_data['comment']); }  ?></textarea>
</div>
<div class="editmenu_wrapper_label editmenu_wrapper_textareaheight"><label for="unsynchronised_lyric">Unsynch Lyrics</label></div>
<div class="editmenu_wrapper_input editmenu_wrapper_textareaheight">
  <textarea name="unsynchronised_lyric" rows="5" cols="60"><?php if(isset($file_data['unsynchronised_lyric'])) { echo htmlspecialchars($file_data['unsynchronised_lyric']); } ?></textarea>
</div>
<div class="editmenu_wrapper_label editmenu_wrapper_textareaheight"><label for="synchronised_lyric">Synched Lyrics</label></div>
<div class="editmenu_wrapper_input editmenu_wrapper_textareaheight">
  <textarea name="synchronised_lyric" rows="5" cols="60"><?php if(isset($file_data['synchronised_lyric'])) { echo htmlspecialchars($file_data['synchronised_lyric']); } ?></textarea>
</div>
<h2 class="editmenu_heading">Tags attached to File</h2>
<table class="editmenu_table_wrapper">
<?php
$taghandling = array(
  "DIRECTORY" => "readonly",
  "FORMAT" => "readonly",
  "CODEC" => "readonly",
  "ARTIST" => "editable",
  "ALBUM" => "editable",
  "GENRE" => "editable",
  "YEAR" => "editable");
function print_table_row($tag_type, $tag_value, $readonly)
{
    echo '<tr class="editmenu_table_tr">';
    echo '<td class="editmenu_table_tagtype">';
    echo $tag_type;
    echo '</td>';
    echo '<td class="editmenu_table_tagtype">';
    if($readonly)
    {
        echo htmlspecialchars($tag_value);
    } else
    {
        echo '<input type="';
        if(0 == strcmp('YEAR', $tag_type))
        {
            echo 'number';
        } else {
            echo 'text';
        }
        echo '" class="editmenu_table_taginput" name="tag_';
        echo $tag_type;
        echo '" value="';
        echo str_replace("\"", "\\\"", $tag_value);
        echo '" />';
    }
    echo '</td>';
    echo '</tr>';
}
foreach($taghandling as $cur_tagtype => $cur_readonly)
{
    $count_found = 0;
    $cur_readonly = 0 == strcmp("readonly", $cur_readonly);
    foreach($file_data['tags'] as $cur_tag)
    {
        if(0 == strcmp($cur_tagtype, $cur_tag['tagtype']))
        {
            print_table_row($cur_tagtype, $cur_tag['tagname'], $cur_readonly);
            $count_found++;
        }
    }
    if(0 == $count_found && !$cur_readonly)
    {
        print_table_row($cur_tagtype, '', $cur_readonly);
    }
}
?>
<input type="submit" class="editmenu_submit" value="save" />
</table>
</form>
</body>
</html>
<?php
    exit(0);
}

?>
