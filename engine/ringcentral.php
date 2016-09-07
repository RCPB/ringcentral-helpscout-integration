<?php

require_once(__DIR__ . '/_bootstrap.php');

use RingCentral\SDK\SDK;


function RCgetVoiceMail($platform, $extension, $dateFrom, $dateTo) {
	
	return $platform->get('/account/~/extension/' . $extension->id . '/message-store', array(
				     'messageType'   => 'VoiceMail',
// 				     'direction'     => 'Inbound',
	                             'dateFrom'      => $dateFrom,
	                             'dateTo'        => $dateTo
				    ))
	                           ->json()->records;
}

function RCsaveVoiceMailAttachments($platform, $logRecord, $saveDir) {
	
	$fdir = $saveDir . '/VoiceMail/' . $logRecord->id . '/';
	if (is_dir($fdir) === false)
	{
	    mkdir($fdir, 0777, true);
	}
	
	$output = array();
	$timePerRecording = 6;
	$status = "success";
	foreach($logRecord->attachments as $attachment) {
	    $id = $attachment->id;

	    
	    $apiResponse = $platform->get($attachment->uri);
	    
	    $ext = ($apiResponse->response()->getHeader('Content-Type')[0] == 'audio/mpeg')
	      ? 'mp3' : 'wav';

	    $filename = "${fdir}/recording_${id}.${ext}";
	    // if (file_exists($filename)) { continue; }
	    
	    $start = microtime(true);
	    
	    $raw = $apiResponse->raw();
	    $raw = preg_replace('/HTTP(.*)en\-GB\R*/s',"",$raw);
	
	    file_put_contents($filename, $raw);
	
	    if(filesize("${fdir}/recording_${id}.${ext}") == 0) {
	        $status = "failure";
		return false;
	    } else {
		$output[] = $filename;
	    }
	
	    file_put_contents("${fdir}/recording_${id}.json", json_encode($attachment));
	    
	    $end=microtime(true);
	
	    // Check if the recording completed wihtin 6 seconds.
	    $time = ($end * 1000 - $start * 1000);
	    if($time < $timePerRecording) {
	      sleep($timePerRecording-$time);
	    }
	}
	return $output;
}

function RCgetExtensions($platform) {
    return $platform->get('/account/~/extension', array('perPage' => 20))->json()->records;
}

function RCgetCallLog($platform, $extension, $dateFrom, $dateTo) {
	
	// Find call log records with recordings
	
	return $platform->get('/account/~/extension/' . $extension->id . '/call-log', array(
	                             'type'          => 'Voice',
				     'direction'     => 'Inbound',
//	                             'withRecording' => 'True',
	                             'dateFrom'      => $dateFrom,
	                             'dateTo'        => $dateTo
				    ))
	                           ->json()->records; /**/
}

function RCsaveCallLogRecording($platform, $callLogRecord, $saveDir) {
	$fdir = $saveDir . '/CallLog/' . $callLogRecord->id . '/';
	if (is_dir($fdir) === false)
	{
	    mkdir($fdir, 0777, true);
	}
	
	$output = array();

	if(!isset($callLogRecord->recording)) {
	    return array();
	}

	$id = $callLogRecord->recording->id;
	$uri = $callLogRecord->recording->contentUri;
	
	$apiResponse = $platform->get($callLogRecord->recording->contentUri);
	    
	$ext = ($apiResponse->response()->getHeader('Content-Type')[0] == 'audio/mpeg')
	      ? 'mp3' : 'wav';
	
	$filename = "${fdir}/recording_${id}.${ext}";
	// if (file_exists($filename) && filesize($filename) > 0) { $output[] = $filename; return $output; }
	    
	$raw = $apiResponse->raw();
	$raw = preg_replace('/HTTP(.*)en\-GB\R*/s',"",$raw);
	
	file_put_contents($filename, $raw);
	
	if(filesize($filename) == 0) {
	    $status = "failure";
	    return false;
	} else {
	    $output[] = $filename;
	}

	file_put_contents("${fdir}/recording_${id}.json", json_encode($callLogRecord));
	
	return $output;
}
