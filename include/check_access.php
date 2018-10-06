<?php

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
        $SESSION_ID = gen_pwd(32, false);
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

?>
