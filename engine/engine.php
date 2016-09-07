<?php
    require_once(__DIR__ . '/ringcentral.php');
    require_once(__DIR__ . '/helpscout.php');

use RingCentral\SDK\SDK;
use HelpScout\ApiClient;
use HelpScout\model\Attachment;

class ConnectEngine {

    private $rc_sdk = null;
    private $rc_platform = null;
    private $hs_api = null;
    private $hs_me = null;
    private $hs_mailbox = null;
    private $saveTo = null;
    private $lockfile = null;
    private $is_restored = true;
    private $emailDomain = '@rc-phonebook.ga';
    private $_extensions = null;
    private $miles = null;

    public function __construct() {
	$this->say("-----");
	$this->authRC();
	$this->authHS();
	$this->saveTo = __DIR__ . '/_cache';
	$this->lockfile = dirname(__DIR__) . '/.lock';
	$this->is_restored = file_exists($this->lockfile);
	if ($this->is_restored) {
	    $this->say('Script was crushed. Restoring data.');
	    $this->miles = file($this->lockfile);
	} else {
	    touch($this->lockfile);
	}
    }

    private function milestone($mile) {
	file_put_contents($this->lockfile, $mile . "\n", FILE_APPEND);
    }

    private function is_skip($mile) {
	if (!$this->is_restored) {
	    return false;
	} else {
	    if (is_string($mile)) { $mile .= "\n"; }
	    $will_skipped = in_array($mile, $this->miles);
	    if($will_skipped) {
		$mile = trim($mile);
		$this->say("Message $mile will skipped.");
	    }
	    return $will_skipped;
	}
    }

    public function cleanup() {
	unlink($this->lockfile);
    }

    protected function getExtensions() {
	if (!$this->_extensions) {
	    $filename = $this->saveTo . '/extensions.json';
	    $youngerThan = time() - 3600 * 24;
	    if (!file_exists($filename) || filemtime($filename) < $youngerThan) {
		$this->say('Updating extensions list...');
		$this->_extensions = RCgetExtensions($this->rc_platform);
		file_put_contents($filename, json_encode($this->_extensions, JSON_PRETTY_PRINT));
	    } else {
		$this->_extensions = json_decode(file_get_contents($filename));
	    }
	    $this->say('Extensions loaded. Count: ' . count($this->_extensions));
	}
	return $this->_extensions;
    }

    protected function say($data) {
	$t = microtime(true);
	$micro = sprintf("%06d",($t - floor($t)) * 1000000);
	$d = new DateTime( date('H:i:s.'.$micro, $t) );

	$time = $d->format("Y-m-d H:i:s.u"); // note at point on "u"
	print($time . '  - ' . $data . PHP_EOL);
    }

    protected function getHSCustomer($phone, $name = '') {
	$customers = $this->hs_api->searchCustomersByEmail($phone . $this->emailDomain);
	if ($customers && $customers->getCount() > 0) {
		$customerRef = new \HelpScout\model\ref\CustomerRef();
		$customerRef->setId($customers->getItems()[0]->getId());
		return $customerRef;
	} else {
		$customerRef = $this->hs_api->getCustomerRefProxy(null, $phone . $this->emailDomain);
		$customerRef->setFirstName($name);
		$customerRef->setPhone($phone);
		return $customerRef;
	}
    }

    private function formatToString($extension) {
	$toString = "";
	if (isset($extension->contact)) {
	    if (isset($extension->contact->firstName)) { $toString .= $extension->contact->firstName . " "; }
	    if (isset($extension->contact->lastName)) { $toString .= $extension->contact->lastName . " "; }
	}
	if (isset($extension->extensionNumber)) { $toString .= "(ext. " . $extension->extensionNumber . ") "; }
	return $toString;
    }

    public function processVoiceMail($fromTime, $toTime) {
	$extensions = $this->getExtensions();
	foreach($extensions as $extension) {
	    if(!isset($extension->extensionNumber)) { continue; }
	    $this->say("Processing voiceMail messages since $fromTime to $toTime for extension " . $extension->extensionNumber);
	    $voicemails = RCgetVoiceMail($this->rc_platform, $extension, $fromTime, $toTime);
	    if(count($voicemails) == 0) {
		$this->say("Nothing to process");
	    }
	    foreach($voicemails as $voicemail) {
		if (!isset($voicemail->from)) { continue; }
		if ($this->is_skip($voicemail->id)) { continue; }
		$this->milestone($voicemail->id);
		$from = $voicemail->from;
		// $this->say("Downloading messages from " . $from->phoneNumber . " since $from to $to");
		if (!isset($from->phoneNumber)) { $from->phoneNumber = $from->extensionNumber; }
		$this->say("Downloading messages from " . $from->phoneNumber);
		$files = RCsaveVoiceMailAttachments($this->rc_platform, $voicemail,  $this->saveTo);
		if (!$files) {
		    $this->say('Download error. Try again');
		    sleep(3);
		    $files = RCsaveVoiceMailAttachments($this->rc_platform, $voicemail,  $this->saveTo);
		}
		$customer = $this->getHSCustomer($from->phoneNumber, isset($from->name) ? $from->name : $from->phoneNumber);
		$this->say('Downloaded: ' . count($files) . ' messages.');
		$this->say('Create conversation with ' . $from->phoneNumber);
		$this->updateHSConversation($customer, $files, 'VoiceMail from ' . $from->phoneNumber . ' to ' . $this->formatToString($extension));
		$this->say('Done');
		sleep(2);
	    };
	    sleep(2);
	};
    }

    public function processCallLog($fromTime, $toTime) {
	$extensions = $this->getExtensions();
	foreach($extensions as $extension) {
	    if(!isset($extension->extensionNumber)) { continue; }
	    $this->say("Processing callLog messages since $fromTime to $toTime for extension " . $extension->extensionNumber);
	    $callRecords = RCgetCallLog($this->rc_platform, $extension, $fromTime, $toTime);
	    if(count($callRecords) == 0) {
		$this->say("Nothing to process");
	    }
	    foreach($callRecords as $callRecord) {
		$from = $callRecord->from;
		if(!isset($from->phoneNumber)) { continue; }
		if ($this->is_skip($callRecord->id)) { continue; }
		$this->milestone($callRecord->id);
		$this->say('Call status: ' . $callRecord->result);
		$isCreateNewConversation = $callRecord->result == 'Missed' || $callRecord->result == 'Voicemail';
		$isNeedDownload = in_array($callRecord->result, array('Missed', /* 'Voicemail', */ 'Accepted'));
		if ($isNeedDownload) {
		    $this->say('Downloading callLog from ' . $from->phoneNumber);
		    $files = RCsaveCallLogRecording($this->rc_platform, $callRecord,  $this->saveTo);
		    if (!$files) {
			$this->say('Download error. Try again');
			sleep(3);
			$files = RCsaveCallLogRecording($this->rc_platform, $callRecord,  $this->saveTo);
		    }
		    $customer = $this->getHSCustomer($from->phoneNumber, isset($from->name) ? $from->name : $from->phoneNumber);
		    $this->say('Downloaded: ' . count($files) . ' messages.');
		    $this->say('Create conversation with ' . $from->phoneNumber);
		    $this->updateHSConversation($customer, $files, 'CallRecording from ' . $from->phoneNumber  . ' to ' . $this->formatToString($extension) . ' (' . $callRecord->result . ') ');
		}
		$this->say('Done');
		sleep(2);
	    };
	    sleep(2);
	}
    }

    protected function updateHSConversation($customer, $files, $subject = 'New Customer Conversation', $createAnyway = false) {
	// Attachments
	$attachments = array();
	    
	foreach($files as $file) {
		$attachment = new Attachment();
		$attachment->setFileName(basename($file));
		$attachment->setMimeType(mime_content_type($file));
		$attachment->setData(file_get_contents($file));

		$this->hs_api->createAttachment($attachment);
		// at this point, the image as been uploaded and is waiting to be attached to a conversation
		$attachments[] = $attachment;
	}


	$conversation = false;
	if (!$createAnyway && $customer->getId() != null) {
	    $conversations = $this->hs_api->getConversationsForCustomerByMailbox($this->hs_mailbox->getId(), $customer->getId());
	    if ($conversations && $conversations->getCount() > 0) {
		$conversation = $this->hs_api->getConversation($conversations->getItems()[0]->getId());
		// $subject = $conversation->getSubject();
		$updated = $subject . ' (updated at ' . date('Y-m-d H:i') . ')';
		// $count = -1;
		/* $subject = preg_replace('/ \(updated at [0-9\-: ]*\)/', $updated, $subject, -1, $count);
		if ($count < 1) {
		    $subject = $subject . $updated;
		} */
		$conversation->setSubject($updated);
	    }
	}

	if (!$conversation) {
	    /* Create conversation flow */
	    // A conversation must have at least one thread
	    $thread = new \HelpScout\model\thread\Customer();
	    $thread->setBody($subject);

	    // add any and all previously uploaded attachments. Help Scout will take
	    // the attachments you've already uploaded and associate them with this conversation.
	    $thread->setAttachments($attachments);

	    // Create by: required
	    $thread->setCreatedBy($customer);

	    $conversation = new \HelpScout\model\Conversation();
	    $conversation->setType     ('phone');
	    $conversation->setSubject  ($subject);
	    $conversation->setCustomer ($customer);
	    $conversation->setCreatedBy($customer);

	    // The mailbox associated with the conversation
	    $conversation->setMailbox  ($this->hs_mailbox->toRef());

	    $conversation->addLineItem($thread);
	    $this->hs_api->createConversation($conversation);
	} else {
	    // Update conversation flow

	    // Message threads are ones created by users of Help Scout and will be emailed out to customers
	    $thread = new \HelpScout\model\thread\Message();
	    $thread->setBody($subject);

	    // Created by: required
	    // The ID given must be a registered user of Help Scout
	    $thread->setCreatedBy($this->hs_me->toRef());

	    // add any and all previously uploaded attachments. Help Scout will take
	    // the attachments you've already uploaded and associate them with this conversation.
	    $thread->setAttachments($attachments);

	    $this->hs_api->createThread($conversation->getId(), $thread);
	    $this->hs_api->updateConversation($conversation);
	}
    }


    protected function authRC () {
	$credentials_file = __DIR__ . '/_credentials.json';
	$credentials = json_decode(file_get_contents($credentials_file), true);

	// Create SDK instance
	$this->rc_sdk = new SDK($credentials['appKey'], $credentials['appSecret'], $credentials['server'], 'Demo', '1.0.0');
	$platform = $this->rc_sdk->platform();

	// Retrieve previous authentication data
	$cacheDir = __DIR__ . DIRECTORY_SEPARATOR . '_cache';
	$file = $cacheDir . DIRECTORY_SEPARATOR . 'platform.json';

	if (!file_exists($cacheDir)) {
	    mkdir($cacheDir);
	}

	$cachedAuth = array();

	if (file_exists($file)) {
	    $cachedAuth = json_decode(file_get_contents($file), true);
	    unlink($file); // dispose cache file, it will be updated if script ends successfully
	}

	$platform->auth()->setData($cachedAuth);

	try {
	    $platform->refresh();
	    $this->say('Authorization was restored');
	
	} catch (Exception $e) {

	    $this->say('Auth exception: ' . $e->getMessage());
	    $auth = $platform->login($credentials['username'], $credentials['extension'], $credentials['password']);

	    $this->say('Authorized');
	}

	// Save authentication data
	file_put_contents($file, json_encode($platform->auth()->data(), JSON_PRETTY_PRINT));
	

	// Init $this->rc_platform
	$this->rc_platform = $platform;
	$this->say('RC API initilized');
	return true;
    }

    protected function authHS() {
	$credentials_file = __DIR__ . '/_credentials.json';
	$credentials = json_decode(file_get_contents($credentials_file), true);

	$helpscout = ApiClient::getInstance();
	$helpscout->setKey($credentials["helpscout"]);

	$mailboxes = $helpscout->getMailboxes();
	if ($mailboxes && $mailboxes->getCount() > 0) {
		// First mailbox is our mailbox
		$mailbox = $mailboxes->getItems()[0];
		// var_dump($mailbox);
		// $conversations = $helpscout->getConversationsForMailbox($mailbox->getId());
	}
	
	$this->hs_me = $helpscout->getUsers()->getItems()[0];
	$this->hs_mailbox = $mailbox;
	$this->hs_api = $helpscout;
	$this->say('HelpScout initialized');
	return true;
    }

}
?>