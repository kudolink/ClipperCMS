<?php
if(!defined('IN_MANAGER_MODE') || IN_MANAGER_MODE != 'true') exit();

if (!$modx->hasPermission('save_user')) {
	$e->setError(3);
	$e->dumpError();
}
?>
<?php

// Web alert -  sends an alert to web browser
function webAlert($msg) {
	global $id, $modx;

	$mode = $_POST['mode'];
	$url = "index.php?a=$mode" . ($mode == '12' ? "&id=" . $id : "");
	$modx->manager->saveFormValues($mode);
	require_once('header.inc.php');
	$modx->webAlert($msg, $url);
	include_once "footer.inc.php";
}

// Generate password
function generate_password($length = 10) {
	$allowable_characters = "abcdefghjkmnpqrstuvxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789";
	$ps_len = strlen($allowable_characters);
	mt_srand((double) microtime() * 1000000);
	$pass = "";
	for ($i = 0; $i < $length; $i++) {
		$pass .= $allowable_characters[mt_rand(0, $ps_len -1)];
	}
	return $pass;
}

$id = intval($_POST['id']);
$oldusername = $_POST['oldusername'];
$newusername = !empty ($_POST['newusername']) ? trim($_POST['newusername']) : "New User";
$newusername_esc = $modx->db->escape($newusername);
$fullname = $modx->db->escape($_POST['fullname']);
$genpassword = $_POST['newpassword'];
$passwordgenmethod = $_POST['passwordgenmethod'];
$passwordnotifymethod = $_POST['passwordnotifymethod'];
$specifiedpassword = $_POST['specifiedpassword'];
$email = $modx->db->escape($_POST['email']);
$oldemail = $_POST['oldemail'];
$phone = $modx->db->escape($_POST['phone']);
$mobilephone = $modx->db->escape($_POST['mobilephone']);
$fax = $modx->db->escape($_POST['fax']);
$dob = !empty ($_POST['dob']) ? ConvertDate($_POST['dob']) : 0;
$country = $_POST['country'];
$state = $modx->db->escape($_POST['state']);
$zip = $modx->db->escape($_POST['zip']);
$gender = !empty ($_POST['gender']) ? $_POST['gender'] : 0;
$photo = $modx->db->escape($modx->config['file_browser'] == 'kcfinder' ? preg_replace('/^'.preg_quote($modx->config['base_url'], '/').'/', '', $_POST['photo']) : $_POST['photo']);
$comment = $modx->db->escape($_POST['comment']);
$roleid = !empty ($_POST['role']) ? $_POST['role'] : 0;
$failedlogincount = $_POST['failedlogincount'];
$blocked = !empty ($_POST['blocked']) ? $_POST['blocked'] : 0;
$blockeduntil = !empty ($_POST['blockeduntil']) ? ConvertDate($_POST['blockeduntil']) : 0;
$blockedafter = !empty ($_POST['blockedafter']) ? ConvertDate($_POST['blockedafter']) : 0;
$user_groups = $_POST['user_groups'];

// verify password
if ($passwordgenmethod == "spec" && $_POST['specifiedpassword'] != $_POST['confirmpassword']) {
	webAlert("Password typed is mismatched");
	exit;
}

// verify email
if ($email == '' || !preg_match("/^[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,6}$/i", $email)) {
	webAlert("E-mail address doesn't seem to be valid!");
	exit;
}

// verify admin security
if ($_SESSION['mgrRole'] != 1) {
	// Check to see if user tried to spoof a "1" (admin) role
	if ($roleid == 1) {
		webAlert("Illegal attempt to create/modify administrator by non-administrator!");
		exit;
	}

	// Verify that the user being edited wasn't an admin and the user ID got spoofed
	$sql = "SELECT role FROM " . $modx->getFullTableName('user_attributes') . " 
	WHERE internalKey = $id";

	if ($rs = $modx->db->query($sql)) {
		if ($rsQty = $modx->db->getRecordCount($rs)) {
			// There should only be one if there is one
			$row = $modx->db->getRow($rs);

			if ($row['role'] == 1) {
				webAlert("You cannot alter an administrative user.");
				exit;
			}
		}
	}

}

switch ($_POST['mode']) {
	case '11' : // new user
		// check if this user name already exist
		$sql = "SELECT id FROM " . $modx->getFullTableName('manager_users') . " 
		WHERE username='$newusername_esc'";
		
		$rs = $modx->db->query($sql);
		$limit = $modx->db->getRecordCount($rs);
		
		if ($limit > 0) {
			webAlert("User name is already in use!");
			exit;
		}

		// check if the email address already exist
		$sql = "SELECT id FROM " . $modx->getFullTableName('user_attributes') . " 
		WHERE email='$email'";

		$rs = $modx->db->query($sql); 
		$limit = $modx->db->getRecordCount($rs);

		if ($limit > 0) {
			$row = $modx->db->getRow($rs);
			if ($row['id'] != $id) {
				webAlert("Email is already in use!");
				exit;
			}
		}

		// generate a new password for this user
		if ($specifiedpassword != "" && $passwordgenmethod == "spec") {
			if (strlen($specifiedpassword) < 6) {
				webAlert("Password is too short!");
				exit;
			} else {
				$newpassword = $specifiedpassword;
			}
		}
		elseif ($specifiedpassword == "" && $passwordgenmethod == "spec") {
			webAlert("You didn't specify a password for this user!");
			exit;
		}
		elseif ($passwordgenmethod == 'g') {
			$newpassword = generate_password(8);
		} else {
			webAlert("No password generation method specified!");
			exit;
		}

		$modx->invokeEvent("OnBeforeUserFormSave", array (
			"mode" => "new",
			"id" => $id
		));

		// build the SQL
		require ('hash.inc.php');
		$HashHandler = new HashHandler(CLIPPER_HASH_PREFERRED, $modx);
		$Hash = $HashHandler->generate($newpassword);

		$sql = 'INSERT INTO ' . $modx->getFullTableName('manager_users') . " 
		(username, hashtype, salt, password)
		VALUES('$newusername_esc', " 
		. CLIPPER_HASH_PREFERRED . ", '" 
		. $modx->db->escape($Hash->salt) . "', '" 
		. $modx->db->escape($Hash->hash) . "')";
		
		$modx->db->query($sql);

		$key = $modx->db->getInsertId();

		$sql = "INSERT INTO " . $modx->getFullTableName('user_attributes') . " 
		(internalKey, fullname, role, email, phone, mobilephone, fax, zip, state, country, gender, dob, photo, comment, blocked, blockeduntil, blockedafter)
		VALUES($key, '$fullname', $roleid, '$email', '$phone', '$mobilephone', '$fax', '$zip', '$state', '$country', '$gender', '$dob', '$photo', '$comment', $blocked, $blockeduntil, $blockedafter)";

		$modx->db->query($sql);

		saveUserSettings($key);

		$modx->invokeEvent("OnManagerSaveUser", array (
			"mode" => "new",
			"userid" => $key,
			"username" => $newusername,
			"userpassword" => $newpassword,
			"useremail" => $email,
			"userfullname" => $fullname,
			"userroleid" => $roleid
		));

		$modx->invokeEvent("OnUserFormSave", array (
			"mode" => "new",
			"id" => $key
		));

		/*******************************************************************************/
		// put the user in the user_groups he/ she should be in
		// first, check that up_perms are switched on!
		if ($use_udperms == 1) {
			if (count($user_groups) > 0) {
				for ($i = 0; $i < count($user_groups); $i++) {
					$sql = "INSERT INTO " . $modx->getFullTableName('member_groups') . " 
					(user_group, member) 
					VALUES ('" . intval($user_groups[$i]) . "', $key)";

					$modx->db->query($sql);
				}
			}
		}

		if ($passwordnotifymethod == 'e') {
			sendMailMessage($email, $newusername, $newpassword, $fullname);

			if ($_POST['stay'] != '') {
				$a = ($_POST['stay'] == '2') ? "12&id=$id" : "11";
				$header = "Location: index.php?a=" . $a . "&r=2&stay=" . $_POST['stay'];
				header($header);
			} else {
				$header = "Location: index.php?a=75&r=2";
				header($header);
			}
		} else {
			if ($_POST['stay'] != '') {
				$a = ($_POST['stay'] == '2') ? "12&id=$key" : "11";
				$stayUrl = "index.php?a=" . $a . "&r=2&stay=" . $_POST['stay'];
			} else {
				$stayUrl = "index.php?a=75&r=2";
			}
			
			require_once('header.inc.php');
?>
			<h1><?php echo $_lang['user_title']; ?></h1>

			<div id="actions">
			<ul class="actionButtons">
				<li><a href="<?php echo $stayUrl ?>"><img src="<?php echo $_style["icons_save"] ?>" /> <?php echo $_lang['close']; ?></a></li>
			</ul>
			</div>

			<div class="sectionHeader"><?php echo $_lang['user_title']; ?></div>
			<div class="sectionBody">
			<div id="disp">
			<p>
			<?php echo sprintf($_lang["password_msg"], $newusername, htmlspecialchars($newpassword)); ?>
			</p>
			</div>
			</div>
		<?php

			include_once "footer.inc.php";
		}
		break;

	case '12' : // edit user
		// generate a new password for this user
		if ($genpassword == 1) {
			if ($specifiedpassword != "" && $passwordgenmethod == "spec") {
				if (strlen($specifiedpassword) < 6) {
					webAlert("Password is too short!");
					exit;
				} else {
					$newpassword = $specifiedpassword;
				}
			}
			elseif ($specifiedpassword == "" && $passwordgenmethod == "spec") {
				webAlert("You didn't specify a password for this user!");
				exit;
			}
			elseif ($passwordgenmethod == 'g') {
				$newpassword = generate_password(8);
			} else {
				webAlert("No password generation method specified!");
				exit;
			}
			
			require ('hash.inc.php');
			$HashHandler = new HashHandler(CLIPPER_HASH_PREFERRED, $modx);
			$Hash = $HashHandler->generate($newpassword);

			$updatepasswordsql = ", hashtype=" . CLIPPER_HASH_PREFERRED . ", salt='" . $modx->db->escape($Hash->salt) . "', password='" . $modx->db->escape($Hash->hash) . "'";
		}

		if ($passwordnotifymethod == 'e') {
			sendMailMessage($email, $newusername, $newpassword, $fullname);
		}

		// check if the username already exists
		$sql = "SELECT id FROM " . $modx->getFullTableName('manager_users') . " 
		WHERE username='$newusername_esc'";
		
		$rs = $modx->db->query($sql);
		$limit = $modx->db->getRecordCount($rs);
		
		if ($limit > 0) {
			$row = $modx->db->getRow($rs);
			if ($row['id'] != $id) {
				webAlert("User name is already in use!");
				exit;
			}
		}

		// check if the email address already exists
		$sql = "SELECT internalKey FROM " . $modx->getFullTableName('user_attributes') . " 
		WHERE email='$email'";

		$rs = $modx->db->query($sql);
		$limit = $modx->db->getRecordCount($rs);

		if ($limit > 0) {
			$row = $modx->db->getRow($rs);

			if ($row['internalKey'] != $id) {
				webAlert("Email is already in use!");
				exit;
			}
		}

		$modx->invokeEvent("OnBeforeUserFormSave", array (
			"mode" => "upd",
			"id" => $id
		));

		// update user name and password
		$sql = "UPDATE " . $modx->getFullTableName('manager_users') . " 
		SET username='$newusername'" . $updatepasswordsql . " 
		WHERE id=$id";
		
		$modx->db->query($sql);

		$sql = "UPDATE " . $modx->getFullTableName('user_attributes') . " 
		SET fullname='$fullname', role='$roleid', email='$email', phone='$phone',
		mobilephone='$mobilephone', fax='$fax', zip='$zip', state='$state',
		country='$country', gender='$gender', dob='$dob', photo='$photo', comment='$comment',
		failedlogincount='$failedlogincount', blocked=$blocked, blockeduntil=$blockeduntil, 
		blockedafter=$blockedafter 
		WHERE internalKey=$id";

		$modx->db->query($sql);

		saveUserSettings($id);

		$modx->invokeEvent("OnManagerSaveUser", array (
			"mode" => "upd",
			"userid" => $id,
			"username" => $newusername,
			"userpassword" => $newpassword,
			"useremail" => $email,
			"userfullname" => $fullname,
			"userroleid" => $roleid,
			"oldusername" => (($oldusername != $newusername
		) ? $oldusername : ""), "olduseremail" => (($oldemail != $email) ? $oldemail : "")));

		if ($updatepasswordsql)
			$modx->invokeEvent("OnManagerChangePassword", array (
				"userid" => $id,
				"username" => $newusername,
				"userpassword" => $newpassword
			));

		$modx->invokeEvent("OnUserFormSave", array (
			"mode" => "upd",
			"id" => $id
		));

		/*******************************************************************************/
		// put the user in the user_groups he/ she should be in
		// first, check that up_perms are switched on!
		if ($use_udperms == 1) {
		// as this is an existing user, delete his/ her entries in the groups before saving the new groups
			$sql = "DELETE FROM " . $modx->getFullTableName('member_groups') . " 
			WHERE member=$id";

			$modx->db->query($sql);

			if (count($user_groups) > 0) {
				for ($i = 0; $i < count($user_groups); $i++) {
					$sql = "INSERT INTO " . $modx->getFullTableName('member_groups') . " 
					(user_group, member) 
					VALUES (" . intval($user_groups[$i]) . ", $id)";

					$modx->db->query($sql);
				}
			}
		}
		// end of user_groups stuff!
		/*******************************************************************************/

		if ($id == $modx->getLoginUserID() && ($genpassword !==1 && $passwordnotifymethod !='s')) {
?>
			<body bgcolor='#efefef'>
			<script language="JavaScript">
			alert("<?php echo $_lang["user_changeddata"]; ?>");
			top.location.href='index.php?a=8';
			</script>
			</body>
		<?php

			exit;
		}
		if ($genpassword == 1 && $passwordnotifymethod == 's') {
			if ($_POST['stay'] != '') {
				$a = ($_POST['stay'] == '2') ? "12&id=$id" : "11";
				$stayUrl = "index.php?a=" . $a . "&r=2&stay=" . $_POST['stay'];
			} else {
				$stayUrl = "index.php?a=75&r=2";
			}
			
			require_once('header.inc.php');
?>
			<h1><?php echo $_lang['user_title']; ?></h1>

			<div id="actions">
			<ul class="actionButtons">
				<li><a href="<?php echo ($id == $modx->getLoginUserID()) ? 'index.php?a=8' : $stayUrl; ?>"><img src="<?php echo $_style["icons_save"] ?>" /> <?php echo ($id == $modx->getLoginUserID()) ? $_lang['logout'] : $_lang['close']; ?></a></li>
			</ul>
			</div>

			<div class="sectionHeader"><?php echo $_lang['user_title']; ?></div>
			<div class="sectionBody">
			<div id="disp">
			<p>
			<?php echo sprintf($_lang["password_msg"], $newusername, htmlspecialchars($newpassword)).(($id == $modx->getLoginUserID()) ? ' '.$_lang['user_changeddata'] : ''); ?>
			</p>
			</div>
			</div>
		<?php
			
			include_once "footer.inc.php";
		} else {
			if ($_POST['stay'] != '') {
				$a = ($_POST['stay'] == '2') ? "12&id=$id" : "11";
				$header = "Location: index.php?a=" . $a . "&r=2&stay=" . $_POST['stay'];
				header($header);
			} else {
				$header = "Location: index.php?a=75&r=2";
				header($header);
			}
		}
		break;
	default :
		webAlert("Unauthorized access");
		exit;
}

// Send an email to the user
function sendMailMessage($email, $uid, $pwd, $ufn) {
	global $signupemail_message;
	global $emailsubject, $emailsender;
	global $site_name, $site_start, $site_url;
	$manager_url = $site_url . "manager/";
	$message = sprintf($signupemail_message, $uid, $pwd); // use old method
	// replace placeholders
	$message = str_replace("[+uid+]", $uid, $message);
	$message = str_replace("[+pwd+]", $pwd, $message);
	$message = str_replace("[+ufn+]", $ufn, $message);
	$message = str_replace("[+sname+]", $site_name, $message);
	$message = str_replace("[+saddr+]", $emailsender, $message);
	$message = str_replace("[+semail+]", $emailsender, $message);
	$message = str_replace("[+surl+]", $manager_url, $message);

	require_once('controls/clipper_mailer.class.inc.php');
	$mail = new ClipperMailer();
	$mail->Subject = $emailsubject;
	$mail->isHTML(false);
	$mail->Body = $message;
	$mail->AddAddress($email);

	if (!$mail->Send()) {
		webAlert("$email - {$_lang['error_sending_email']}");
	}

}

// Save User Settings
function saveUserSettings($id) {
	global $modx;

	//$config = array();
	//$rs = $modx->db->query('SELECT * FROM '.$modx->getFullTableName('system_settings'));
	//while ($row = $modx->db->getRow($rs, 'num'))
	//	$config[$row[0]] = $row[1];

	// array of post values to ignore in this function
	$ignore = array(
		'id',
        'internalKey',
		'oldusername',
		'oldemail',
		'newusername',
		'fullname',
		'newpassword',
		'newpasswordcheck',
		'passwordgenmethod',
		'passwordnotifymethod',
		'specifiedpassword',
		'confirmpassword',
		'email',
		'phone',
		'mobilephone',
		'fax',
		'dob',
		'country',
		'state',
		'zip',
		'gender',
		'photo',
		'comment',
		'role',
		'failedlogincount',
		'blocked',
		'blockeduntil',
		'blockedafter',
		'user_groups',
		'mode',
		'blockedmode',
		'stay',
		'save'
	);

	// determine which settings can be saved blank (based on 'default_{settingname}' POST checkbox values)
	$defaults = array(
		'upload_images',
		'upload_media',
		'upload_flash',
		'upload_files'
	);

	// get user setting field names
	$settings= array ();

	foreach ($_POST as $n => $v) {
        if(is_array($v)){
            $v = implode(",", $v);
        }
		if (in_array($n, $ignore) || (!in_array($n, $defaults) && trim($v) == '')) continue; // ignore blacklist and empties

		//if ($config[$n] == $v) continue; // ignore commonalities in base config

		$settings[$n] = $v; // this value should be saved
	}

	foreach ($defaults as $k) {
		if (isset($settings['default_'.$k]) && $settings['default_'.$k] == '1') {
			unset($settings[$k]);
		}
		unset($settings['default_'.$k]);
	}

	$usrTable = $modx->getFullTableName('user_settings');
	$modx->db->query("DELETE FROM $usrTable WHERE user= $id");

	$savethese = array();
	foreach ($settings as $k => $v) {
	    if(is_array($v)) $v = implode(',', $v);
	    $savethese[] = '('.$id.', \''.$modx->db->escape($k).'\', \''.$modx->db->escape($v).'\')';
	}

	$sql = "INSERT INTO $usrTable (user, setting_name, setting_value)
	VALUES " . implode(', ', $savethese);

	$modx->db->query($sql);
}

// converts date format dd-mm-yyyy to php date
function ConvertDate($date) {
	global $modx;
	if ($date == "") {return "0";}
	else {}          {return $modx->toTimeStamp($date);}
}
?>
