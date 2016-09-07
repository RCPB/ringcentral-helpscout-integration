<?php 

include_once(dirname(__FILE__) . "/../api/src/HelpScout/ApiClient.php");
include_once(dirname(__FILE__) . "/../api/vendor/autoload.php");

// Helpscout Bootstrap
use HelpScout\ApiClient;

define('PHONE_DOMAIN', '@phonedomain.com');


function getCustomerRefByPhone ($phone) {
	global $helpscout;
	$customers = $helpscout->searchCustomersByEmail($phone . PHONE_DOMAIN);
	if ($customers && $customers->getCount() > 0) {
		$customerRef = new \HelpScout\model\ref\CustomerRef();
		$customerRef->setId($customers->getItems()[0]->getId());
		return $customerRef;
	} else {
		$customerRef = $client->getCustomerRefProxy(null, $phone . PHONE_DOMAIN);
		return $customerRef;
	}
}

function createConversation ($customer_phone, $text, $attachment = false) {
	
	global $helpscout;
	global $me;
	
	$note = new \HelpScout\model\thread\Note();
	$note->setBody($text);

	if ($attachment) {
		// to create a new conversation with a note and an attachment
		$at = new \HelpScout\model\Attachment();
		$at->load($attachment);
		$helpscout->createAttachment($at);
		$note->addAttachment($at);
	}
	
	// if you already know the ID of the Help Scout user, you can simply get a ref
	$userRef = $helpscout->getUserRefProxy($me->getId());
	
	$note->setCreatedBy($userRef);
	
	$convo = new \HelpScout\model\Conversation();
	$convo->setMailbox($helpscout->getMailboxProxy($mailbox->getId()));
	$convo->setCreatedBy($userRef);
	$convo->setSubject("RC conversation with $customer_phone [Auto-generated]");
	
	// every conversation must be tied to a customer
	// Test Customer Id = 74329273
	
	// The customer associated with the conversation
	$customerRef = new \HelpScout\model\ref\CustomerRef();
	$customerRef->setId(74329273);
	// .. Or create it
	// $customerRef = $client->getCustomerRefProxy(null, 'customer@example.com');
	
	
	$convo->setCustomer($customerRef);
	
	$convo->addLineItem($note);
	
	$helpscout->createConversation($convo);
}

?>