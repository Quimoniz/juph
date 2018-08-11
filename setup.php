<?php
//1. if no admin password is in INI-file, generate it write incomplete config.ini,
//   tell the invoking user to read the file and enter the password
//2. check if passwords match, if they do, ask for database connection details, music folder
//3. if password is correct and configuration options are given,
//   then write out complete config.ini file, setup mysql-database

function server_error($message = "Internal Server Error")
{
    header('HTTP/1.1 500 Server error');
    echo "<!DOCTYPE html>\n<html>\n<head>\n<title>Server Error</title>\n</head>\n";
    echo "<h1>Internal Server Error</h1>\n";
    echo "<h1>" . htmlspecialchars($message) . "</h1>\n";
    echo "<body>\n</body>\n</html>";
}



if(!file_exists($CONFIG_FILE))
{
    //generate admin password
    $admin_password = '';
    //use chr(int val) to generate char from int
    //use rand(int min, int max) to generate random number
    function gen_pwd($pwd_len = 15)
    {
        $chr_grp = array(array(35,38),array(48,57),array(58,63),array(65,90),array(97,122));
        $chr_used_grp = array(0,0,0,0,0);
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
    $admin_password = gen_pwd(15);
    touch($CONFIG_FILE);
    chmod($CONFIG_FILE, 0740);
    $fp = fopen($CONFIG_FILE, 'w');
    fwrite($fp, "ADMIN_PWD=\"" . $admin_password . "\"\n");
    fclose($fp);
    unset($admin_password);
    $CONFIG_VAR = parse_ini_file($CONFIG_FILE);
} else
{
    // config.ini exists
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
    echo 'correct_password';
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
  console.log("response!");
  alert(ajax.responseText);
}
</script>
</head>
<body>
<form method="POST" action="./" onsubmit="return ajax_setup_authentication();">
<input type="text" name="user" size="10" value="juph_setup" />
<input type="password" name="pwd" size="20" value="" />
<input type="submit" />
</form>
</body>
</html>

<?php
}

?>
