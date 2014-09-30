<?php

/*
 +-----------------------------------------------------------------------+
 | lib/drivers/lcsieve.php                                               |
 |                                                                       |
 | Copyright (C) 2014 Tobias Unsleber                                    |
 | Licensed under the GNU GPL                                            |
 +-----------------------------------------------------------------------+
 */

/*
 * Read driver function.
 *
 * @param array $data the array of data to get and set.
 *
 * @return integer the status code.
 */


function _log($msg) {
	$log = fopen("/tmp/roundcube_lcsieve.log","a");
	fwrite($log,$msg);
	fclose($log);
}

function _info($msg) {
	_log("$msg\n");
}

function _err($msg) {
	_log($log,"ERROR: $msg\n");
}

function _create_sieve_file($filename,$contract,$userfolder,$address,$subject,$message) {

	$tempfile=tempnam(sys_get_temp_dir(), "vacation-");	
	chmod($tempfile,444);
	$sieve_file = fopen($tempfile,"w");
	if(!$sieve_file) {
		$error=error_get_last();
		_err("Cannot open sieve file location $sieve_file: ".$error["message"]);
		return;
	}
	fwrite($sieve_file,"# Created by RoundCube\n");
	fwrite($sieve_file,"require [\"vacation\"];\n");
	fwrite($sieve_file,"vacation\n");
	fwrite($sieve_file,"  :days 1\n");
	fwrite($sieve_file,"  :subject \"$subject\"\n");
	fwrite($sieve_file,"  :addresses [\"$address\"]\n");
	fwrite($sieve_file,"\"$message\";\n");
	fclose($sieve_file);
	#_info("Executing command: /usr/bin/sudo -u mail /usr/local/bin/set_sieve_autoreply '$contract' '$userfolder' '$tempfile'");
	system("/usr/bin/sudo -u mail /usr/local/bin/set_sieve_autoreply '$contract' '$userfolder' '$tempfile'");
	# unlink($tempfile);
	return;

}

function _delete_sieve_file($contract,$userfolder) {
	system("/usr/bin/sudo -u mail /usr/local/bin/set_sieve_autoreply '$contract' '$userfolder' 'delete'");
}

function _set_vacation_data($rcmail, $mail_local, $mail_domain, $vacation_message, $vacation_subject, $vacation_enable) {

        $lc_db_file 		= $rcmail->config->get('vacation_lcsieve_liveconfig_db_file');
	if(!$lc_db_file) { _err("Variable vacation_lcsieve_liveconfig_db_file not defined, exiting"); }

	try {
		$db 	= new SQLite3("$lc_db_file"); }
	catch ( Exception $e ) {
		 _err("$lc_db_file: ".$e->getMessage().", exting");
		return;
	}
		

	$autoreply_db_value=($vacation_enable == '1')?"1":"0";
	$query 	= "UPDATE MAILBOXES"
			   ." SET MB_AUTOREPLYSUB = '$vacation_subject',"
			   ."	  MB_AUTOREPLYMSG  = '$vacation_message',"
			   ."     MB_AUTOREPLY     = '$autoreply_db_value'"			
			   ." WHERE "
			   ." 	     MB_NAME   = '$mail_local'"
			   ."    AND MB_DOMAIN = '$mail_domain';";
	#_info("$query");
	# _err("$query");

	try {
		$results = $db->exec("$query"); }
	catch ( Exception $e ) {
		 _err("$lc_db_file: ".$e->getMessage().", exting");
		return;
	}

	list ($sieve_file,$contract,$userfolder) =_get_sieve_file($rcmail,$mail_local,$mail_domain);
	if(!$sieve_file) { _err("Cannot determine sieve file for user $mail_local@$mail_domain, exiting"); return; } 

	if ($autoreply_db_value=='1') {
		_create_sieve_file($sieve_file,$contract,$userfolder,"$mail_local@$mail_domain",$vacation_subject,$vacation_message);
	} else {
		_delete_sieve_file($contract,$userfolder);
	}

	if(!$results) {
		_err("Error executing sqlite update: $sqlite_error");
		$db->close();
		return;
	}
	$db->close();
	_info("updated vacation information for: $mail_local@$mail_domain");
	return;
}

function _get_vacation_data($rcmail, $mail_local, $mail_domain) {

// select MB_NAME,MB_DOMAIN,MB_AUTOREPLYSUB,MB_AUTOREPLYMSG,MB_AUTOREPLY from MAILBOXES where length(MB_AUTOREPLYMSG) > 10 limit 10;

        $lc_db_file 		= $rcmail->config->get('vacation_lcsieve_liveconfig_db_file');
	if(!$lc_db_file) { _err("Variable vacation_lcsieve_liveconfig_db_file not defined, exiting"); }

	$lc_mail_base_path 	= rtrim($lc_mail_base_path,"/");

	try {
		$db 	= new SQLite3("$lc_db_file"); }
	catch ( Exception $e ) {
		 _err("$lc_db_file: ".$e->getMessage().", exting");
		return;
	}
		

	$query 	= "select MB_AUTOREPLYSUB,MB_AUTOREPLYMSG,MB_AUTOREPLY"			
			. " from MAILBOXES"			
			. " where "
			. " 	   	MAILBOXES.MB_NAME 	= '$mail_local' "
			. "	AND	MAILBOXES.MB_DOMAIN	= '$mail_domain';";
	# _err("$query");
	$results = $db->query("$query");
	while ($row = $results->fetchArray()) {
		$autoreply_active  = ( $row["MB_AUTOREPLY"]   ) ? $row["MB_AUTOREPLY"]      : 0;
		$autoreply_subject = ( $row["MB_AUTOREPLYSUB"] ) ? $row["MB_AUTOREPLYSUB"]  : "Abwesenheitsinformation";
		$autoreply_message = ( $row["MB_AUTOREPLYMSG"] ) ? $row["MB_AUTOREPLYMSG"]  : "Leider bin ich derzeit ausser Haus und werde Ihre Mail erst bei Rueckkehr wieder lesen. \n\nVielen Dank fuer Ihr Verstaendnis";
		$record_found=1;
	}
	if(!$record_found) {
		_err("Account not found in Liveconfig DB, exiting");
		$db->close();
		return;
	}
	$db->close();
	_info("fetched vacation information for: $mail_local@$mail_domain");

	return array($autoreply_active, $autoreply_subject, $autoreply_message);
}

function _get_sieve_file($rcmail, $mail_local, $mail_domain) {

        $lc_db_file 		= $rcmail->config->get('vacation_lcsieve_liveconfig_db_file');
	if(!$lc_db_file) { _err("Variable vacation_lcsieve_liveconfig_db_file not defined, exiting"); }

        $lc_mail_base_path 	= $rcmail->config->get('vacation_lcsieve_liveconfig_mail_base_path');
	if(!$lc_mail_base_path) { _err("Variable vacation_lcsieve_liveconfig_mail_base_path not defined, exiting"); }

	$lc_mail_base_path 	= rtrim($lc_mail_base_path,"/");
	#_info($lc_mail_base_path);

	try {
		$db 	= new SQLite3("$lc_db_file"); }
	catch ( Exception $e ) {
		 _err("$lc_db_file: ".$e->getMessage().", exting");
		return;
	}
		

	$query 	= "select HC_NAME,MB_FOLDER"			
			. " from MAILBOXES,HOSTINGCONTRACTS"			
			. " where "
			. " 		MAILBOXES.MB_CONTRACTID = HOSTINGCONTRACTS.HC_ID "
			. " 	AND	MAILBOXES.MB_NAME 	= '$mail_local' "
			. "	AND	MAILBOXES.MB_DOMAIN	= '$mail_domain';";
	# _err("$query");
	$results = $db->query("$query");

	while ($row = $results->fetchArray()) {
		if($row["HC_NAME"] and $row["HC_NAME"]) {
			$contract   = $row["HC_NAME"];
			$userfolder = $row["MB_FOLDER"];
    			$sieve_file_path="$lc_mail_base_path/$contract/$userfolder";
		} else {
			_err("Account not found in Liveconfig DB, exiting");
			$db->close();
			return;
		}
	}
	$db->close();

	return array($sieve_file_path."/dovecot.sieve",$contract,$userfolder);
}

function vacation_read(array &$data)
{

	$rcmail = rcmail::get_instance();
	if(!$rcmail) { _err("Cannot get config object!"); return;}
	
	list ( $vacation_active, $vacation_subject, $vacation_message ) = _get_vacation_data($rcmail,$data["email_local"],$data["email_domain"]);

    	# $sieve_file_path=_get_sieve_file($rcmail,$data["email_local"],$data["email_domain"]);
	#if(!$sieve_file_path) { _err("Cannot get sieve-file path for user: ".$data["email"]); return; }

	$data['vacation_enable']  = "$vacation_active";
	$data['vacation_message'] = "$vacation_message";
	$data['vacation_subject'] = "$vacation_subject";

	return PLUGIN_SUCCESS;
}

/*
 * Write driver function.
 *
 * @param array $data the array of data to get and set.
 *
 * @return integer the status code.
 */
function vacation_write(array &$data)
{
	$rcmail = rcmail::get_instance();
	if(!$rcmail) { _err("Cannot get config object!"); return;}

	if( 
			!preg_match("/^[-a-zA-Z0-9_.]{1,40}$/"				,$data["email_local"])
		or	!preg_match("/^([-a-zA-Z0-9_.]{1,30}\.){1,5}[a-zA-Z]{2,15}$/"	,$data["email_domain"]) ) {

		_err("Invalid email address, aborting");
		return;
	}

	if( !preg_match("/^[^`\$]{1,1000}$/m",$data["vacation_message"]) ) {
		_err("Invalid characters in message text, aborting");
		return;
	}

	if(  !preg_match("/^[-_:a-zA-Z0-9\. ]{1,200}$/",$data["vacation_subject"]) ) {
		_err("Invalid characters in subject, aborting");
		return;
	}
	if( $data["vacation_enable"] == "1" ) {
		$vacation_enabled="1";
	} else {
		$vacation_enabled="0";
	}
	
	_set_vacation_data($rcmail, $data["email_local"],$data["email_domain"],$data["vacation_message"],$data["vacation_subject"],$vacation_enabled);

	return PLUGIN_SUCCESS;
}
