<?php
//1. if no admin password is in INI-file, generate it write incomplete config.ini,
//   tell the invoking user to read the file and enter the password
//2. check if passwords match, if they do, ask for database connection details, music folder
//3. if password is correct and configuration options are given,
//   then write out complete config.ini file, setup mysql-database

function write_config_file($config_var, $dst_file)
{
    $fp = fopen($dst_file, "w");
    fwrite($fp, "<" . "?php\n");
    if(is_array($config_var))
    {
        fwrite($fp, "\$CONFIG_VAR = array();\n");
        
        foreach($config_var as $childKey => $childValue)
        {   
            fwrite($fp,"\$CONFIG_VAR[\"" . escape_config_value($childKey) . "\"] = \"" . escape_config_value($childValue) . "\";\n");
        }   
    }
    fwrite($fp, '?' . '>');
    fclose($fp);
}
function escape_config_value($sourceVal)
{
    $outVal = str_replace(array("\"", "\$", "\\", "\n"), array("\\\"", "\\\$", "\\\\", "\\n"), $sourceVal);
    return $outVal;
}

function check_db_connectable($db_addr, $db_port, $db_user, $db_pwd, $db_db)
{
    $dbcon = @new mysqli($db_addr, $db_port, $db_user, $db_pwd, $db_db, (int) $db_port);
    if(!$dbcon || $dbcon->connect_errno)
    {
        return false;
    } else
    {
        $dbcon->close();
        return true;
    }
}






if(!file_exists($CONFIG_FILE))
{
    //generate admin password
    $admin_password = '';
    //use chr(int val) to generate char from int
    //use rand(int min, int max) to generate random number
    $admin_password = gen_pwd(15, true);
    touch($CONFIG_FILE);
    chmod($CONFIG_FILE, 0740);
    $CONFIG_VAR = array( "ADMIN_PWD" => $admin_password);
    write_config_file($CONFIG_VAR, $CONFIG_FILE);
    unset($admin_password);
} else
{
    // config.ini exists at this point
    if(is_null($CONFIG_VAR))
    {
        server_error("Error while accessing file system");
        exit(0);
    } else
    {
        //file exists, config loaded in $CONFIG_VAR
        //do nothing
    }
}

if(isset($_POST['pwd']) && $CONFIG_VAR['ADMIN_PWD'] === $_POST['pwd'])
{
    if(isset($_POST['setup_configuration']))
    {
        $CONFIG_VAR['DB_ADDR'] = $_POST['db_addr'];
        $CONFIG_VAR['DB_PORT'] = $_POST['db_port'];
        $CONFIG_VAR['DB_DB'] = $_POST['db_db'];
        $CONFIG_VAR['DB_USER'] = $_POST['db_user'];
        $CONFIG_VAR['DB_PWD'] = $_POST['db_pwd'];
        $CONFIG_VAR['MUSIC_DIR_ROOT'] = $_POST['music_dir_root'];
        $CONFIG_VAR['ACCESS_PWD'] = $_POST['access_pwd_param'];
        if(!check_db_connectable($CONFIG_VAR['DB_ADDR'], $CONFIG_VAR['DB_PORT'], $CONFIG_VAR['DB_USER'], $CONFIG_VAR['DB_PWD'], $CONFIG_VAR['DB_DB']))
        {
            client_error("Could not connect to database");
            exit(0);
        }
        if(0 == strlen($CONFIG_VAR['MUSIC_DIR_ROOT']) || !is_dir($CONFIG_VAR['MUSIC_DIR_ROOT']) || '/' == $CONFIG_VAR['MUSIC_DIR_ROOT'][strlen($CONFIG_VAR['MUSIC_DIR_ROOT']) - 1])
        {
            clien_error("Invalid MUSIC_DIR_ROOT");
            exit(0);
        }
        $CONFIG_VAR['setup_complete'] = "true";
        write_config_file($CONFIG_VAR, $CONFIG_FILE);
        echo "entered into config.ini!";
    } else
    {
        echo "<style type=\"text/css\">\nlabel {\nwidth: 150px;\n}\n</style>\n";
        echo "<form method=\"POST\" action=\"./\">\n";
        echo "<input type=\"hidden\" name=\"pwd\" value=\"" . htmlspecialchars($_POST['pwd']) . "\"/>";
        echo "<input type=\"hidden\" name=\"setup_configuration\" value=\"true\"/>";
        echo "<label for=\"db_addr\">Database address:</label><input type=\"text\" id=\"db_addr\" name=\"db_addr\" size=\"25\"/><br/>\n";
        echo "<label for=\"db_port\">Database port:</label><input type=\"text\" id=\"db_port\" name=\"db_port\" size=\"25\" value=\"3306\"/><br/>\n";
        echo "<label for=\"db_db\">Database database:</label><input type=\"text\" id=\"db_db\" name=\"db_db\" size=\"25\"/><br/>\n";
        echo "<label for=\"db_user\">Database user:</label><input type=\"text\" id=\"db_user\" name=\"db_user\" size=\"25\"/><br/>\n";
        echo "<label for=\"db_pwd\">Database password:</label><input type=\"text\" id=\"db_pwd\" name=\"db_pwd\" size=\"25\"/><br/>\n";
        echo "<label for=\"music_dir_root\">Music dir root:</label><input type=\"text\" id=\"music_dir_root\" name=\"music_dir_root\" size=\"25\"/><br/>\n";
        echo "<label for=\"access_pwd_param\">Access password:</label><input type=\"text\" id=\"access_pwd_param\" name=\"access_pwd_param\" size=\"25\"/><br/>\n";
        echo "<input type=\"submit\" />";
        echo "</form>\n";
    }
} else {
?>
<!DOCTYPE html5>
<html>
<head>
<meta charset="utf8" />
<title>Setup</title>
<script type="text/javascript">
var ajax = undefined;
function ajax_setup_authentication()
{
  ajax = new XMLHttpRequest();
  ajax.open("POST", "./", true);
  ajax.addEventListener("load", ajax_process_authentication);
  ajax.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
  ajax.send("pwd=" + encodeURIComponent(document.getElementsByName("pwd")[0].value));
  return false;
}
function ajax_process_authentication()
{
  console.log(ajax.responseText);
  document.getElementById("main_wrapper").innerHTML = ajax.responseText;
}
</script>
</head>
<body>
<div id="main_wrapper">
<form method="POST" action="./" onsubmit="return ajax_setup_authentication();">
<input type="text" name="user" size="10" value="juph_setup" />
<input type="password" name="pwd" size="20" value="" />
<input type="submit" />
</form>
</div>
</body>
</html>

<?php
}

?>
