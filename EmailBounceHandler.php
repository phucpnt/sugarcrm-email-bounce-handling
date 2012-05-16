/******************************************************


Copyright (c) 2012 Milsoft Utility Solutions Inc.

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.


======================================================

Code originally written by Nigel Bosch to handle bounced emails from email campaigns and mark those invalid email addresses as invalid in SugarCRM


Install into custom/modules/Schedulers/


******************************************************/





<?php
if(!defined('sugarEntry'))define('sugarEntry', true);

if ( isset($_GET['manual']) && $_GET['manual'] == 'true' )
	handleBounced();

function handleBounced() {
	// Set up what we need to create a SugarBean for database access.
	require_once('include/entryPoint.php');
	require_once('data/SugarBean.php');
	$focus = new SugarBean();

	if ( !($conn = POP3connect()) )
		return;
	if ( !($msginfos = getMetaMessages($conn)) )
		return;
	echo '<br />' . count($msginfos) . " messages found in inbox.\n";

	$total = 0;
	$todelete = array();
	foreach ( $msginfos as $msginfo ) {
		$msghead = imap_fetchheader($conn, $msginfo->msgno, FT_PREFETCHTEXT);
		$body = imap_fetchbody($conn, $msginfo->msgno, '1');
		// Make a guess about whether the email is a bounceback or not.
		if ( (preg_match('/55[034]/', $body) ||
			preg_match('/^subject.*undeliver(ed|able)/mi', $msghead) ||
			preg_match('/^subject.*delivery.*failure/mi', $msghead)) &&
			(preg_match('/invalid\s*recipient/i', $body) ||
			preg_match('/address\s*rejected/i', $body) ||
			preg_match('/unable\s*to\s*deliver/i', $body) ||
			preg_match('/no\s*such\s*recipient/i', $body) ||
			preg_match('/mailbox\s*unavailable/i', $body) ||
			preg_match('/permanent\s*failure/i', $body) ||
			preg_match('/account\s*does\s*not\s*exist/i', $body) ||
			preg_match('/address\s*is\s*incorrect/i', $body) ||
			preg_match('/did\s*not\s*reach.*recipient/i', $body) ||
			preg_match('/delivery\s*to.*recipient.*failed/i', $body) ||
			preg_match('/could\s*not\s*be\s*reached/i', $body) ||
			preg_match('/could\s*not\s*be\s*delivered/i', $body)) ) {
			$bounced = processBouncedEmail($msghead, $body, $focus);
			$total += $bounced;
			if ( $bounced > 0 )
				$todelete[] = $msginfo->msgno;
		} else if ( preg_match('/out\s*of\s*(the)?\s*office/i', $body) ||
			preg_match('/auto(matic)?.?reply/i', $body) ||
			preg_match('/on\s*vacation/i', $body) ||
			preg_match('/^subject.*out\s*of\s*(the)?\s*office/mi', $msghead) ||
			preg_match('/^subject.*auto(matic)?.?reply/mi', $msghead) ) {
			// Out of office reminders
			$todelete[] = $msginfo->msgno;
		} else {
			// Unrecognized emails.
		}
	}
	echo "<br />Set $total email addresses as invalid.\n";
	POP3disconnect($conn);

	// Delete messages.  For some reason it only deletes some messages and then gets
	// sick of it and stops working.
	if ( !($conn = POP3connect()) )
		return;
	foreach ( $todelete as $num )
		imap_delete($conn, $num);
	echo '<br />Attempted to delete ' . count($todelete) . " email(s).\n";
	POP3disconnect($conn);
	return;
}

// Make a POP3 connection.
function POP3connect() {
	$connection = imap_open('{mail.milsoft.com:110/pop3}INBOX', 'sugar', 'mil*soft');
	if ( !$connection ) {
		echo "<br />Could not connect to sugar@milsoft.com using POP3.\n";
		return false;
	}
	echo "<br />Connected to sugar@milsoft.com using POP3.\n";
	return $connection;
}

// Expunge deleted messages and close the connection.
function POP3disconnect(&$connection) {
	if ( !imap_expunge($connection) )
		echo "<br />Could not expunge deleted messages.\n";
	if ( imap_close($connection) ) {
		echo "<br />Closed POP3 connection.\n";
		return true;
	}
	echo "<br />Could not close POP3 connection.\n";
	return false;
}

// Get some meta information for all emails (not the header) and return it in an array.
function getMetaMessages(&$connection) {
	$status = imap_check($connection);
	if ( !$status ) {
		echo "<br />Could not get status of sugar@milsoft.com using POP3.\n";
		return false;
	}
	$msginfos = imap_fetch_overview($connection, '1:' . $status->Nmsgs);
	if ( !$msginfos ) {
		echo '<br />Could not get message information for ' . $status->Nmsgs . " messages.\n";
		return false;
	}
	return $msginfos;
}

// Takes the body of an email that has determined to be a bounceback email, attempts to
// extract an email address from the email, and marks that address as invalid in the
// SugarCRM database.
function processBouncedEmail(&$head, &$body, &$focus) {
	$emailchars = 'a-zA-Z0-9\.\Q!#$%&*+-=?^_`{|}~\E';
	$emailexp = "/[$emailchars]+@[$emailchars]+\.[$emailchars]+/";
	$match = array();
	$headmatch = array();
	$email = '';
	// Attempt to retrieve recipient address from message header.
	if ( preg_match('/^x-propel-info-debug_0.+/im', $head, $headmatch) &&
		preg_match($emailexp, $headmatch[0], $match) )
		$email = $match[0];
	// Otherwise try get it from the email body.
	if ( empty($email) ) {
		if ( !preg_match_all($emailexp, $body, $match) )
			return 0;
		$email = $match[0][0];
		// Check to make sure this is the only email address in the message.
		foreach ( $match as $m )
			if ( $m[0] != $email )
				return 0;
	}
	$res = $focus->db->query("SELECT COUNT(*) AS cnt FROM email_addresses WHERE email_address='$email'");
	$count = 0;
	if ( $row = $focus->db->fetchByAssoc($res) )
		$count = $row['cnt'];
	$focus->db->query("UPDATE email_addresses SET invalid_email=1, date_modified=NOW() WHERE email_address='$email'");
	echo "<br />Set as invalid $count record(s) matching '$email'.\n";
	return $count;
}
?>
