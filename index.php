<?php 
    error_reporting(-1);
    ini_set('display_errors', TRUE);
    ini_set('max_execution_time', 0);
    set_time_limit(-1);
    ignore_user_abort(true);
    
    $file_s = __DIR__ . DIRECTORY_SEPARATOR . 'date.info';
    $time1 = intval(file_get_contents($file_s));
    $time1_s = date("c", $time1);
    $time2 = time();
    $time2_s = date("c", $time2);
    
    require('engine/engine.php');
    $engine = new ConnectEngine();

    try {
	$engine->processVoiceMail($time1_s, $time2_s);
	$engine->processCallLog($time1_s, $time2_s);
	$engine->cleanup();
	file_put_contents($file_s, $time2);
    } catch (Exception $e) {
	echo 'We\'ve got an exception: ',  $e->getMessage(), "\n";
	print $e->getTraceAsString() . PHP_EOL;
	// var_dump($e);
    }

?>