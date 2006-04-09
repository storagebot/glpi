<?php
/*
 * @version $Id$
 ----------------------------------------------------------------------
 GLPI - Gestionnaire Libre de Parc Informatique
 Copyright (C) 2003-2006 by the INDEPNET Development Team.
 
 http://indepnet.net/   http://glpi.indepnet.org
 ----------------------------------------------------------------------

 LICENSE

	This file is part of GLPI.

    GLPI is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    GLPI is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with GLPI; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 ------------------------------------------------------------------------
*/

// Based on:
// IRMA, Information Resource-Management and Administration
// Christian Bauer 
// ----------------------------------------------------------------------
// Original Author of file:
// Purpose of file:
// ----------------------------------------------------------------------
// And Julien Dombre for externals identifications
// And Marco Gaiarin for ldap features
 

include ("_relpos.php");
include ($phproot . "/glpi/includes.php");
include ($phproot . "/glpi/includes_users.php");
include ($phproot . "/glpi/includes_setup.php");

// load default dictionnary 
	loadLanguage();


//$database=$cfg_db["database"];
//SetCookie("cfg_dbdb",$database,0,"/");

$_POST=array_map('stripslashes',$_POST);

//Do login and checks
//echo "test";
$update_list = array();
$user_present=1;
if (!isset($_POST['login_name'])) $_POST['login_name']="";
$identificat = new Identification();

$auth_succeded=false;

if (!isset($_POST["noCAS"])&&!empty($cfg_glpi["cas_host"])) {
	include ($phproot . "/glpi/CAS/CAS.php");
	phpCAS::client(CAS_VERSION_2_0,$cfg_glpi["cas_host"],intval($cfg_glpi["cas_port"]),$cfg_glpi["cas_uri"]);

	// force CAS authentication
	phpCAS::forceAuthentication();
	$user=phpCAS::getUser();
	$auth_succeded=true;
	$identificat->extauth=1;
	$user_present = $identificat->user->getFromDBbyName($user);
	if (!$user_present) $identificat->user->fields["name"]=$user;
}
if (isset($_POST["noCAS"])) $_SESSION["noCAS"]=1;

if (!$auth_succeded) // Pas de tests en configuration CAS
if (empty($_POST['login_name'])||empty($_POST['login_password'])){
$identificat->err=$lang["login"][8];
} else {

	// exists=0 -> no exist
	// exists=1 -> exist with password
	// exists=2 -> exist without password
	$exists=$identificat->userExists($_POST['login_name']);

	// Pas en premier car sinon on ne fait pas le blankpassword
	// First try to connect via le DATABASE
	if ($exists==1){
		// Without UTF8 decoding
		if (!$auth_succeded) $auth_succeded = $identificat->connection_db($_POST['login_name'],$_POST['login_password']);
		if ($auth_succeded) $user_present = $identificat->user->getFromDBbyName($_POST['login_name']);
		
		// With UTF8 decoding
		//if (!$auth_succeded) $auth_succeded = $identificat->connection_db(utf8_decode($_POST['login_name']),utf8_decode($_POST['login_password']));
		//if ($auth_succeded) $user_present = $identificat->user->getFromDBbyName(utf8_decode($_POST['login_name']));
	
	}

	// Second try IMAP/POP
	if (!$auth_succeded&&!empty($cfg_glpi["imap_auth_server"])) {
		$auth_succeded = $identificat->connection_imap($cfg_glpi["imap_auth_server"],utf8_decode($_POST['login_name']),utf8_decode($_POST['login_password']));
		if ($auth_succeded) {
			$identificat->extauth=1;
			$user_present = $identificat->user->getFromDBbyName($_POST['login_name']);

			if ($identificat->user->getFromIMAP($cfg_glpi["imap_host"],utf8_decode($_POST['login_name']))) {
				$update_list = array('email');
			}
		}
	}

	// Third try LDAP in depth search
	// we check all the auth sources in turn...
	// First, we get the dn and then, we try to log in
	if (!$auth_succeded&&!empty($cfg_glpi["ldap_host"])) {
	   	$found_dn=false;
   		$auth_succeded=0;
   		$found_dn=$identificat->ldap_get_dn($cfg_glpi["ldap_host"],$cfg_glpi["ldap_basedn"],utf8_decode($_POST['login_name']),$cfg_glpi["ldap_rootdn"],$cfg_glpi["ldap_pass"],$cfg_glpi["ldap_port"]);
	   	if ($found_dn!=false&&!empty($_POST['login_password'])){ 
		    $auth_succeded = $identificat->connection_ldap($cfg_glpi["ldap_host"],$found_dn,utf8_decode($_POST['login_name']),utf8_decode($_POST['login_password']),$cfg_glpi["ldap_condition"],$cfg_glpi["ldap_port"]);
			if ($auth_succeded) {
				$identificat->extauth=1;
				$user_present = $identificat->user->getFromDBbyName($_POST['login_name']);
				$update_list = array();
				if ($identificat->user->getFromLDAP($cfg_glpi["ldap_host"],$cfg_glpi["ldap_port"],$found_dn,$cfg_glpi["ldap_rootdn"],$cfg_glpi["ldap_pass"],$cfg_glpi['ldap_fields'],utf8_decode($_POST['login_name']))) {
					$update_list = array_keys($cfg_glpi['ldap_fields']);
				}
			}
	   	}
	}

	// Fourth try for flat LDAP 
	// LDAP : Try now with the first base_dn
	if (!$auth_succeded&&!empty($cfg_glpi["ldap_host"])) {
		$auth_succeded = $identificat->connection_ldap($cfg_glpi["ldap_host"],$cfg_glpi["ldap_basedn"],utf8_decode($_POST['login_name']),utf8_decode($_POST['login_password']),$cfg_glpi["ldap_condition"],$cfg_glpi["ldap_port"]);
		if ($auth_succeded) {
			$identificat->extauth=1;
			$user_present = $identificat->user->getFromDBName($_POST['login_name']);
			$update_list = array();
			if ($identificat->user->getFromLDAP($cfg_glpi["ldap_host"],$cfg_glpi["ldap_port"],$cfg_glpi["ldap_basedn"],$cfg_glpi["ldap_rootdn"],$cfg_glpi["ldap_pass"],$cfg_glpi['ldap_fields'],utf8_decode($_POST['login_name']))) {
				$update_list = array_keys($cfg_glpi['ldap_fields']);
			}
		}
	}

	// Fifth try Active directory LDAP in depth search
	// we check all the auth sources in turn...
	// First, we get the dn and then, we try to log in
	if (!$auth_succeded&&!empty($cfg_glpi["ldap_host"])) {
	   	//echo "AD";
   		$found_dn=false;
	   	$auth_succeded=0;
	   	$found_dn=$identificat->ldap_get_dn_active_directory($cfg_glpi["ldap_host"],$cfg_glpi["ldap_basedn"],$_POST['login_name'],$cfg_glpi["ldap_rootdn"],$cfg_glpi["ldap_pass"],$cfg_glpi["ldap_port"]);
   		//echo $found_dn."---";
	   	if ($found_dn!=false&&!empty($_POST['login_password'])){ 
		    $auth_succeded = $identificat->connection_ldap_active_directory($cfg_glpi["ldap_host"],$found_dn,$_POST['login_name'],$_POST['login_password'],$cfg_glpi["ldap_condition"],$cfg_glpi["ldap_port"]);
			if ($auth_succeded) {
				$identificat->extauth=1;
				$user_present = $identificat->user->getFromDBbyName($_POST['login_name']);
				$update_list = array();
				if ($identificat->user->getFromLDAP_active_directory($cfg_glpi["ldap_host"],$cfg_glpi["ldap_port"],$found_dn,$cfg_glpi["ldap_rootdn"],$cfg_glpi["ldap_pass"],$cfg_glpi['ldap_fields'],$_POST['login_name'],$cfg_glpi["ldap_condition"])) {
				$update_list = array_keys($cfg_glpi['ldap_fields']);
				}
			}
   		}
	}

} // Fin des tests de connexion

// Ok, we have gathered sufficient data, if the first return false the user
// are not present on the DB, so we add it.
// if not, we update it.

if ($auth_succeded)
if (!$user_present&&$cfg_glpi["auto_add_users"]) {
	$identificat->user->fields["ID"]=$identificat->user->addToDB($identificat->extauth);
} else if (!$user_present){ // Auto add not enable so auth failed
	$identificat->err.=$lang["login"][11];
	$auth_succeded=false;	
} else if ($user_present) {
	if (!$identificat->user->fields["active"]){
		$identificat->err.=$lang["login"][11];
		$auth_succeded=false;	
	} else {
		if (!empty($update_list))
			$identificat->user->updateInDB($update_list);
		// Blank PWD to clean old database for the external auth
		if ($identificat->extauth)
			$identificat->user->blankPassword();
	}
}


// we have done at least a good login? No, we exit.
if ( ! $auth_succeded ) {
	nullHeader("Login",$_SERVER["PHP_SELF"]);
	echo "<div align='center'><b>".$identificat->getErr().".</b><br><br>";
	echo "<b><a href=\"".$cfg_glpi["root_doc"]."/logout.php\">".$lang["login"][1]."</a></b></div>";
	nullFooter();
	logevent(-1, "system", 1, "login", $lang["log"][41]." : ".$_POST['login_name']);
	exit;
}

// now we can continue with the process...
$identificat->setcookies();

$dirplug=$phproot."/plugins";
$dh  = opendir($dirplug);
while (false !== ($filename = readdir($dh))) {
	if ($filename!="CVS"&&$filename!="."&&$filename!=".."&&is_dir($dirplug."/".$filename)){
		$_SESSION["glpi_plugins"][]=$filename;
	}
}


// GET THE IP OF THE CLIENT
$ip = (getenv("HTTP_X_FORWARDED_FOR")
? getenv("HTTP_X_FORWARDED_FOR")
: getenv("REMOTE_ADDR"));


// Log Event
logEvent("-1", "system", 3, "login", $_POST['login_name']." ".$lang["log"][40]." : ".$ip);

// Expire Event Log
if ($cfg_glpi["expire_events"]>0){
	$secs =  $cfg_glpi["expire_events"]*86400;
	$query_exp = "DELETE FROM glpi_event_log WHERE UNIX_TIMESTAMP(date) < UNIX_TIMESTAMP()-$secs";
	$result_exp = $db->query($query_exp);
} 

// Redirect management
$REDIRECT="";
if (isset($_POST['redirect']))
$REDIRECT="?redirect=".$_POST['redirect'];

// Redirect to Command Central if not post-only
if ($identificat->user->fields['type'] == "post-only")
{
	glpi_header("helpdesk.php$REDIRECT");
}
else
{
	glpi_header("central.php$REDIRECT");
}

?>
