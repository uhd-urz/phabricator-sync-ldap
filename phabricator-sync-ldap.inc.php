<?php

$mail_str = "";

function debug($str) {
	global $mail_str;

	if (DEBUG) {
		print($str);
		$mail_str .= $str;
	}
}

function send_report() {
	global $mail_str;

	if (!empty($mail_str) && defined("DEBUG_MAILTO") && defined("DEBUG_MAILFROM")) {
		assert(!empty(DEBUG_MAILTO));
		assert(!empty(DEBUG_MAILFROM));
		mail(DEBUG_MAILTO, "Phabricator LDAP synchronisation report", $mail_str, "From: " . DEBUG_MAILFROM . "\r\n");
	}
}
register_shutdown_function("send_report");

function newCronContentSource() {
  return PhabricatorContentSource::newForSource(
    PhabricatorCronContentSource::SOURCECONST);
}

// LDAP

function create_ldap_connection($uri, $binddn, $bindpw) {
	$connection = ldap_connect($uri);
	assert($connection !== null);

	$success = ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
	assert($success !== false);
	$success = ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);
	assert($success !== false);

	$success = ldap_bind($connection, $binddn, $bindpw);
	assert($success !== false);

	return $connection;
}

function destroy_ldap_connection($connection) {
	$success = ldap_unbind($connection);
	assert($success !== false);
}

function query_ldap($connection, $search_base, $filter) {
	$search_result = ldap_search($connection, $search_base, $filter);
	assert($search_result !== false);
	$entries = ldap_get_entries($connection, $search_result);
	assert($search_result !== false);
	return $entries;
}

function associate_ldap_list_with_dn($list) {
	$list_with_dn = array();
	foreach ($list as $item) {
		if (!isset($item["dn"])) {
			// TODO: Investigate! Probably the "count" and similar list members.
			continue;
		}
		$dn = strtolower($item["dn"]);
		$list_with_dn[$dn] = $item;
	}
	return array_filter($list_with_dn);
}

function query_ldap_user_by_username($ld, $username) {
	$connection = $ld["connection"];
	$user_filter = $ld["user"]["filter"];
	$user_name_attr = $ld["user"]["name_attr"];
	$search_filter = sprintf("(&(%s)(%s=%s))", $user_filter, $user_name_attr, $username);

	$search_base = $ld["user"]["search_base"];
	$user = query_ldap($connection, $search_base, $search_filter);

	if ($user["count"] == 0) {
		return null;
	}

	assert($user["count"] == 1);
	return $user[0];
}

// PHABRICATOR

function getField($object, $field_key, $user) {
	$role = PhabricatorCustomField::ROLE_APPLICATIONTRANSACTIONS;
	$fields = PhabricatorCustomField::getObjectFields($object, $role)
		->setViewer($user)
		->readFieldsFromStorage($object)
		->getFields();

	return idx($fields, $field_key)
		->getProxy()
		->getFieldValue();
}

function modifyProjectMembers($project, $members_diff, $viewer) {
	$projectname = $project->getName();

	if (empty($members_diff['+']) && empty($members_diff['-'])) {
		return;
	}

	if (DEBUG && !empty($members_diff['+'])) {
		debug("Will add members to project '" . $projectname . "':\n");
		foreach ($members_diff['+'] as $memberphid) {
			$user = id(new PhabricatorPeopleQuery())
				->setViewer($viewer)
				->withPHIDs(array($memberphid))
				->executeOne();
			$username = $user->getUserName();
			debug("  " . $username . " (" . $memberphid . ")\n");
		}
	}

	if (DEBUG && !empty($members_diff['-'])) {
		debug("Will remove members from project '" . $projectname . "':\n");
		foreach ($members_diff['-'] as $memberphid) {
			$user = id(new PhabricatorPeopleQuery())
				->setViewer($viewer)
				->withPHIDs(array($memberphid))
				->executeOne();
			$username = $user->getUserName();
			debug("  " . $username . " (" . $memberphid . ")\n");
		}
	}

	if (!DRY_RUN) {
		$type_member = PhabricatorProjectProjectHasMemberEdgeType::EDGECONST;

		$xactions = array();
		$xactions[] = id(new PhabricatorProjectTransaction())
			->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
			->setMetadataValue('edge:type', $type_member)
			->setNewValue($members_diff);

		$editor = id(new PhabricatorProjectTransactionEditor())
			->setActor($viewer)
			->setContentSource(newCronContentSource())
			->setContinueOnNoEffect(true)
			->setContinueOnMissingFields(true)
			->applyTransactions($project, $xactions);
	}
}

// MAPS

// FIXME: We need a change in semantics:
// * If a user is linked to an AD account, but we cannot find that within LDAP, we need to disable him. If there is an LDAP object for a user, but no corresponding Phabricator account, we could create one - but we do not do that currently!
// * If a project has a LDAP DN CustomField set, but we cannot find that within LDAP, we need to disable it. If a project does not have an LDAP DN CustomField set, we do not want to track it.

function map_users($phab_accounts, $ld, $map_empty = true) {
	$userdn_userphid_map = array();
	$userphid_userdn_map = array();

	foreach ($phab_accounts as $account) {
		$phab_userphid = $account->getuserPHID();

		$ldap_username = explode("@", $account->getAccountID(), 2)[0];
		assert($ldap_username !== null);

		$ldap_user = query_ldap_user_by_username($ld, $ldap_username);
		$ldap_dn = $ldap_user ? $ldap_user["dn"] : null;
		$ldap_dn = $ldap_dn ? strtolower($ldap_dn) : null;
		if ($ldap_dn) {
			$userdn_userphid_map[$ldap_dn] = $phab_userphid;
		}

		// FIXME: If a LDAP DN CustomField was set, but we cannot find a corresponding LDAP object, the user was deleted - we need to track that, so we can disable him later!
		if ($ldap_dn or $map_empty) {
			$userphid_userdn_map[$phab_userphid] = $ldap_dn;
		}
	}

	return array("by_dn" => $userdn_userphid_map, "by_phid" => $userphid_userdn_map);
}

function map_projects($phab_projects, $phab) {
	$phab_admin = $phab["admin"];
	$phab_project_ldap_dn_field = $phab["project_ldap_dn_field"];

	$groupdn_projectphid_map = array();
	$projectphid_groupdn_map = array();

	foreach ($phab_projects as $project) {
		$phab_projectphid = $project->getPHID();

		$ldap_dn = getField($project, $phab_project_ldap_dn_field, $phab_admin);
		if ($ldap_dn) {
			$ldap_dn = strtolower($ldap_dn);
			$groupdn_projectphid_map[$ldap_dn] = $phab_projectphid;
			$projectphid_groupdn_map[$phab_projectphid] = $ldap_dn;
		}
	}

	return array("by_dn" => $groupdn_projectphid_map, "by_phid" => $projectphid_groupdn_map);
}

// UPDATE

function update_phab_users($user_map, $ldap_users, $ld, $phab) {
	$phab_admin = $phab["admin"];
	$ldap_user_name_attr = $ld["user"]["name_attr"];

	$users_diff = array('+' => array(), '-' => array());

	if (CREATE_USERS) {
		$userdn_userphid_map = $user_map["by_dn"];
		$users_diff['+'] = array_filter($userdn_userphid_map, function($userphid) {
			/* Add users which are currently missing in Phabricator */
			return ($userphid == null);
		});
	}

	if (DELETE_USERS) {
		$userphid_userdn_map = $user_map["by_phid"];
		$users_diff['-'] = array_filter($userphid_userdn_map, function($userdn) {
			/* Delete users which are currently missing in LDAP */
			// FIXME: AD users can expire or be disabled, thus we also need to check LDAP attributes "accountExpires" and "userAccountControl"
			// For the latter this would be interpreting userAccountControl as a bitmask (LDAP bitwise AND rule 1.2.840.113556.1.4.803) and checking the bits with e.g.: (userAccountControl:1.2.840.113556.1.4.803:=2)
			// Bits according to KB305144: 0x2: disabled, 0x16: lockout, 0x800000: password expired
			// See-Also: http://blogs.technet.com/b/heyscriptingguy/archive/2005/05/12/how-can-i-get-a-list-of-all-the-disabled-user-accounts-in-active-directory.aspx
			// See-Also: http://blogs.technet.com/b/mempson/archive/2011/08/24/useraccountcontrol-flags.aspx
			// See-Also: https://support.microsoft.com/en-us/kb/305144
			return ($userdn == null);
		});
	}

	if (DEBUG && !empty($users_diff['+'])) {
		debug("Will create users:\n");
		foreach ($users_diff['+'] as $userdn => $userphid) {
			$user = $ldap_users[$userdn];
			assert($user !== null);
			$usernames = $user[$ldap_user_name_attr];
			assert($usernames["count"] == 1);
			$username = $usernames[0];
			debug("  " . $username . " (" . $userdn . ")\n");
		}
	}

	if (!DRY_RUN) {
		// TODO: Actually create user
	}

	if (DEBUG && !empty($users_diff['-'])) {
		debug("Will disable users:\n");
		foreach ($users_diff['-'] as $userphid => $userdn) {
			$user = id(new PhabricatorPeopleQuery())
				->setViewer($phab_admin)
				->withPHIDs(array($userphid))
				->executeOne();
			$username = $user->getUserName();
			debug("  " . $username . " (" . $userphid . ")\n");
		}
	}

	if (!DRY_RUN) {
		// TODO: Actually deactivate user
	}
}

function update_phab_projects($project_map, $ldap_users, $ld, $phab) {
	$phab_admin = $phab["admin"];
	$ldap_group_name_attr = $ld["group"]["name_attr"];

	$projects_diff = array('+' => array(), '-' => array());

	if (CREATE_PROJECTS) {
		$groupdn_projectphid_map = $project_map["by_dn"];
		$projects_diff['+'] = array_filter($groupdn_projectphid_map, function($projectphid) {
			/* Add projects which are currently missing in Phabricator */
			return ($projectphid == null);
		});
	}

	if (DELETE_PROJECTS) {
		$projectphid_groupdn_map = $project_map["by_phid"];
		$projects_diff['-'] = array_filter($projectphid_groupdn_map, function($groupdn) {
			// Luckily AD groups cannot expire or be disabled, so we do not need to check anything but their existence
			// FIXME: Do not disable those projects, which have no LDAP DN CustomField set! Only those where the referenced LDAP object does not exist!
			return ($groupdn == null);
		});
	}

	if (DEBUG && !empty($projects_diff['+'])) {
		debug("Will create projects:\n");
		foreach ($projects_diff['+'] as $groupdn => $projectphid) {
			$group = $ldap_groups[$groupdn];
			assert($group !== null);
			$groupnames = $group[$ldap_group_name_attr];
			assert($groupnames["count"] == 1);
			$groupname = $groupnames[0];
			debug("  " . $groupname . " (" . $groupdn . ")\n");
		}
	}

	if (!DRY_RUN) {
		// TODO: Actually create project
	}

	if (DEBUG && !empty($projects_diff['-'])) {
		debug("Will disable projects:\n");
		foreach ($projects_diff['-'] as $projectphid => $groupdn) {
			$project = id(new PhabricatorProjectQuery())
				->setViewer($phab_admin)
				->withPHIDs(array($projectphid))
				->executeOne();
			$projectname = $project->getName();
			debug("  " . $projectname . " (" . $projectphid . ")\n");
		}
	}

	if (!DRY_RUN) {
		// TODO: Actually disable project
	}
}

function update_phab_project_members($project_map, $user_map, $phab_projects, $ldap_groups, $ld, $phab) {
	$phab_admin = $phab["admin"];
	$phab_protected_users = $phab["protected_users"];
	$ldap_group_member_attr = $ld["group"]["member_attr"];

	$userdn_userphid_map = $user_map["by_dn"];
	$projectphid_groupdn_map = $project_map["by_phid"];
	foreach ($projectphid_groupdn_map as $projectphid => $groupdn) {
		// FIXME: If the configured LDAP DN does not actually exist, this will blow up!
		assert(isset($ldap_groups[$groupdn]));
		$group = $ldap_groups[$groupdn];

		// FIXME: If the LDAP group has no members, this will blow up!
		assert(isset($group[$ldap_group_member_attr]));
		$group_members = $group[$ldap_group_member_attr];
		$group_member_phids = array();
		foreach ($group_members as $userdn) {
			$userdn = strtolower($userdn);
			$userphid = idx($userdn_userphid_map, $userdn);

			if ($userphid) {
				$group_member_phids[] = $userphid;
			}
		}

		$project = $phab_projects[$projectphid]; // This is safe, because we map from Phab to LDAP and not the other way round
		$project_member_phids = $project->getMemberPHIDs();

		$members_diff = array('+' => array(), '-' => array());

		if (ADD_PROJECT_MEMBERS) {
			$members_diff['+'] = array_fuse(array_diff($group_member_phids, $project_member_phids));
		}

		if (REMOVE_PROJECT_MEMBERS) {
			$members_diff['-'] = array_fuse(array_diff($project_member_phids, $group_member_phids));
		}

		// Prevent modification of "protected" users
		foreach (array('+', '-') as $action) {
			foreach ($members_diff[$action] as $userphid) {
				$user = id(new PhabricatorPeopleQuery())
					->setViewer($phab_admin)
					->withPHIDs(array($userphid))
					->executeOne();
				$username = $user->getUsername();

				if (in_array($username, $phab_protected_users)) {
					unset($members_diff[$action][$userphid]);
				}
			}
		}

		modifyProjectMembers($project, $members_diff, $phab_admin);
	}
}
