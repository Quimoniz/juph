<?php
$CONFIG_FILE = 'config.ini';
$CONFIG_VAR = NULL;
$do_setup = true;
if( file_exists($CONFIG_FILE))
{
    $CONFIG_VAR = parse_ini_file($CONFIG_FILE);
    if(isset($CONFIG_VAR['setup_complete']) && $CONFIG_VAR['setup_complete'])
    {
        $do_setup = false;
    }
}
if( $do_setup)
{
    include('setup.php');
    exit(0);
}





?>
