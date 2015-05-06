#!/usr/bin/php
<?php

	assert_options(ASSERT_BAIL, 1);

// TODO
// Make it so when users login it doesn't ask for their real name or email??
// clean up how I fetch the $admin object
// pull out more stuff into constants, eg ldap attributes uid and mail
// so it's more easily configurable.


// Note, this script only adds phabricator projects, it
// currently doesn't remove projects. The same goes for users.
// It does remove users from projects, if their respective
// ldap group doesn't match.
//
// Note, our setup makes it so app.stash.* in ou/Groups are where all
// our git permissions/group stuff is. You can change this for your setup.
//
// export LDAP_USER="uid=phab_service,ou=People,dc=mgmt,dc=mydomain,dc=net"
// export LDAP_PASS="pleasedon'tmakeususep4gitfusionshit"
// export LDAP_USER_BASE="ou=People,dc=mgmt,dc=mydomain,dc=net"
// export LDAP_GROUPS_BASE="ou=People,dc=mgmt,dc=mydomain,dc=net"
// export LDAP_URI="ldap://10.0.0.1:389"
//
// You should modify these constants below to configure this script
// for your environment and setup.
define('LDAPDN', getenv('LDAP_USER'));
define('LDAPPASS', getenv('LDAP_PASS'));
define('LDAP_URI', getenv('LDAP_URI'));
define('LDAP_BASE', getenv('LDAP_BASE'));
define('LDAP_GROUP_BASE', getenv('LDAP_GROUP_BASE'));
define('LDAP_USER_BASE', getenv('LDAP_USER_BASE'));

define('LDAP_GROUP_FILTER', 'objectClass=group');
define('LDAP_USER_FILTER', 'objectClass=user');
define('LDAP_GROUP_NAME_ATTR', 'cn');
define('LDAP_GROUP_MEMBER_ATTR', 'member');
define('LDAP_GROUP_MEMBER_TRGT', 'dn');
define('LDAP_USER_NAME_ATTR', 'name');
define('LDAP_USER_MAIL_ATTR', 'mail');
define('LDAP_USER_GROUP_ATTR', 'memberOf');
define('LDAP_USER_GROUP_TRGT', 'dn');

define('DRY_RUN', getenv('DRY_RUN'));
define('CREATE_USERS', getenv('CREATE_USERS'));
define('CREATE_PROJECTS', getenv('CREATE_PROJECTS'));
define('ADD_PROJECT_MEMBERS', getenv('ADD_PROJECT_MEMBERS'));
define('REMOVE_PROJECT_MEMBERS', getenv('REMOVE_PROJECT_MEMBERS'));

assert(LDAPDN !== FALSE);
assert(LDAPPASS !== FALSE);
assert(LDAP_URI !== FALSE);
assert(LDAP_GROUP_BASE !== FALSE);
assert(LDAP_USER_BASE !== FALSE);
assert(LDAP_GROUP_FILTER !== FALSE);
assert(LDAP_USER_FILTER !== FALSE);
assert(LDAP_GROUP_NAME_ATTR !== FALSE);
assert(LDAP_GROUP_MEMBER_ATTR !== FALSE);
assert(LDAP_USER_NAME_ATTR !== FALSE);
assert(LDAP_USER_MAIL_ATTR !== FALSE);

if (DRY_RUN) {
	print(">>> DRY RUN <<<\n");
}

$root = dirname(__FILE__); // make this a constant?
require_once $root.'/scripts/__init_script__.php';

define("PHAB_PROJECT_LDAP_DN_FIELD", "std:project:mycustomfield:ldap:dn");
define("PHAB_USER_LDAP_DN_FIELD", "std:user:mycustomfield:ldap:dn");
define("PHAB_USER_ACCOUNT_TYPE", "AccountType");

function getField($object, $id, $user) {
	$fields = PhabricatorCustomField::getObjectFields($object, PhabricatorCustomField::ROLE_VIEW)
		->setViewer($user)
		->readFieldsFromStorage($object)
		->getFields();
	$field = $fields[$id]
		->getProxy()
		->getFieldValue();
	return $field;
}

// This returns an ldap object, which can be used to query ldap.
function create_ldap_connection() {
	$ldapconn = ldap_connect(LDAP_URI);
	ldap_set_option($ldapconn, LDAP_OPT_PROTOCOL_VERSION, 3);
	ldap_set_option($ldapconn, LDAP_OPT_REFERRALS, 0);
	$bindresult = ldap_bind($ldapconn, LDAPDN, LDAPPASS);
	assert($bindresult);
	return $ldapconn;
}

function get_ldap_user_from_username($ldapconn, $username) {
	$search_base = LDAP_BASE ? LDAP_USER_BASE . ',' . LDAP_BASE : LDAP_USER_BASE;
	$search_filter = sprintf("(&(%s)(%s=%s))", LDAP_USER_FILTER, LDAP_USER_NAME_ATTR, $username);
	$search_result = ldap_search($ldapconn, $search_base, $search_filter);
	$user = ldap_get_entries($ldapconn, $search_result);
	assert($user["count"] == 1);
	return $user[0];
}

function get_ldap_groups($ldapconn) {
	$search_base = LDAP_BASE ? LDAP_GROUP_BASE . ',' . LDAP_BASE : LDAP_GROUP_BASE;
	$search_result = ldap_search($ldapconn, $search_base, LDAP_GROUP_FILTER);
	$groups = ldap_get_entries($ldapconn, $search_result);
	return $groups;
}

// This return { 'john_doe' => { 'mail' => 'john_doe@mydomain.com', 'uid' => 'john_doe' }, ...  }
function get_ldap_users($ldapconn) {
	$search_base = LDAP_BASE ? LDAP_USER_BASE . ',' . LDAP_BASE : LDAP_USER_BASE;
	$search_result = ldap_search($ldapconn, $search_base, LDAP_USER_FILTER);
	$users = ldap_get_entries($ldapconn, $search_result);
	return $users;
}

function associate_ldap_list_with_dn($list) {
	$list_with_dn = array();
	foreach ($list as $item) {
		$list_with_dn[$item["dn"]] = $item;
	}
	return array_filter($list_with_dn);
}


// This returns phabricator objects in the following form:
// { 'project_a' => { 'object' => phab_object }, ... }
// the result of this map is cached and used later also
function get_phab_projects($admin) {
	$projects = id(new PhabricatorProjectQuery())
		->setViewer($admin)
		->needMembers(true)
		->execute();
	return $projects;
}

function associate_phab_projects_with_ldap_dn($projects, $admin) {
	$projects_with_ldap_dn = array();
	foreach ($projects as $project) {
		$ldap_dn = getField($project, PHAB_PROJECT_LDAP_DN_FIELD, $admin);
		$projects_with_ldap_dn[$ldap_dn] = $project;
	}
	return $projects_with_ldap_dn;
}

function get_phab_accounts($admin) {
	$accounts = id(new PhabricatorExternalAccountQuery())
		->setViewer($admin)
		->withAccountTypes(array(PHAB_USER_ACCOUNT_TYPE))
		->execute();
	return $accounts;
}

function get_phab_user_from_account($account, $admin) {
	$userphid = $account->getuserPHID();
	$user = id(new PhabricatorPeopleQuery())
		->setViewer($admin)
		->withPHIDs(array($userphid))
		->executeOne();
	return $user;
}

function get_all_users($ldapconn, $admin) {
	$phab_accounts = get_phab_accounts($admin);

	$users = array();
	foreach ($phab_accounts as $phab_account) {
		$ldap_username = explode("@", $phab_account->getAccountID(), 2)[0];

		$phab_user = get_phab_user_from_account($phab_account, $admin);

		$ldap_user = get_ldap_user_from_username($ldapconn, $ldap_username);
		$ldap_dn = $ldap_user["dn"];

		$users[$ldap_dn] = array(
			"phab_account" => $phab_account,
			"phab_user" => $phab_user,
			"ldap_user" => $ldap_user,
		);
	}

	return $users;
}

function get_all_groups($ldapconn, $admin) {
	$phab_projects_raw = get_phab_projects($admin);
	$phab_projects = associate_phab_projects_with_ldap_dn($phab_projects_raw, $admin);

	$ldap_groups_raw = get_ldap_groups($ldapconn);
	$ldap_groups = associate_ldap_list_with_dn($ldap_groups_raw);

	$groups = array();
	foreach ($ldap_groups as $ldap_dn => $ldap_group) {
		$phab_project = $phab_projects[$ldap_dn];
		$groups[$ldap_dn] = array(
			"phab_project" => $phab_project,
			"ldap_group" => $ldap_group,
		);
	}
	foreach ($phab_projects as $ldap_dn => $phab_project) {
		$groups[$ldap_dn] = array(
			"phab_project" => $phab_project,
			"ldap_group" => null,
		);
	}

	return $groups;
}



mpull($projects, 'getMemberPHIDs');

foreach ($groups as $ldap_dn => $group) {
	$ldap_group_members = $group["ldap_group"][LDAP_GROUP_MEMBER_ATTR];
	$ldap_group_member_phids = array();
	foreach ($ldap_group_members as $member_ldap_dn) {
	  $ldap_group_member_phids[] = $users[$member_ldap_dn]["phab_user"]->getPHID();
	}
}

function get_phab_project_members() {
	$memberphids = $project->getMemberPHIDs();
	$users = id(new PhabricatorPeopleQuery())
		->setViewer($admin)
		->withPHIDs($memberphids)
		->execute();
}

function get_ldap_group_members() {

}

$groups = get_all_groups($ldapconn, $admin);
foreach ($groups as $group) {

}

// [ 'uncreated_project_a', 'uncreated_project_b'... ]
function get_missing_projects($phab_projects, $ldap_groups) {
	return array_filter(array_diff($ldap_groups, $phab_projects));
}


// [ 'uncreated_user_a', 'uncreated_user_b', ... ]
function get_missing_users($phab_users, $ldap_users) {
	return array_filter(array_diff($ldap_users, $phab_users));
}


function create_phab_project($project_name, $admin) {
	assert(!DRY_RUN and CREATE_PROJECTS);

	$project = PhabricatorProject::initializeNewProject($admin);
	$project->setAuthorPHID($admin->getPHID());

	$type_name  = PhabricatorProjectTransaction::TYPE_NAME;
	$xactions   = array();
	$xactions[] = id(new PhabricatorProjectTransaction())
		->setTransactionType($type_name)
		->setNewValue($project_name);

	$content_source = PhabricatorContentSource::newForSource(
		PhabricatorContentSource::SOURCE_CONSOLE,
		array());

	$editor = id(new PhabricatorProjectTransactionEditor())
		->setActor($admin)
		->setContinueOnNoEffect(true)
		->setContentSource($content_source);
	$editor->applyTransactions($project, $xactions);
}


// return a PhabricatorUser object
function create_phab_user($ldap_user, $admin) {
	assert(!DRY_RUN and CREATE_USERS);

	$email = $ldap_user['mail'][0];
	$uid   = $ldap_user['uid'][0];
	$phab_user = id(new PhabricatorUser())
		->setUsername($uid)
		->setIsApproved(True)
		->setRealName($uid);
	$phab_user_email = id(new PhabricatorUserEmail())
		->setAddress($email)
		->setIsVerified(True)
		->setUserPHID($phab_user->getPHID());

	$editor = id(new PhabricatorUserEditor())
		->setActor($admin);
	$editor->createNewUser($phab_user, $phab_user_email);
}


// This will set 'add' and 'remove' keys onto each project's map/cache.
// The ldap_groups is the master list.
function get_phab_project_member_edges($admin, $phab_project, $expected_members) {
	print("HURRA2\n");
	$expected_members_by_phid   = mpull($expected_members, null, 'getPHID');
	print("HURRA\n");
	print_r($expected_members);
	print_r($expected_members_by_phid);
	//  $users   = mpull($users, null, 'getUsername');
	//  $users   = mpull($users, null, 'getPHID');
	$current_memberphids = $phab_project->getMemberPHIDs();
	$all_members = mpull($expected_members_by_phid, null, 'getPHID');
	if (count($current_memberphids) > 0) {
		$current_users = id(new PhabricatorPeopleQuery())
			->setViewer($admin)
			->withPHIDs($current_memberphids)
			->execute();
		$all_members += mpull($current_memberphids, null, 'getPHID');
	}

	$add = array_diff_key($expected_members_by_phid, array_fuse($current_members));
	$remove = array_diff_key(array_fuse($current_memberphids), $expected_members_by_phid);
	$result = array();
	$result['add'] = array_select_keys($all_members, array_keys($add));
	$result['remove'] = array_select_keys($all_members, array_keys($remove));
	return $result;
}


function create_phab_project_edges() {
	return False;
}

$admin = id(new PhabricatorPeopleQuery())
	->setViewer(PhabricatorUser::getOmnipotentUser())
	->withUsernames(array('admin'))
	->executeOne();

$ldapconn                = create_ldap_connection();
assert($ldapconn);

$ldap_groups             = get_ldap_groups($ldapconn);
$ldap_group_names        = array_keys($ldap_groups);
$ldap_users              = get_ldap_users($ldapconn);

$phab_projects           = get_phab_projects($admin);
$phab_project_names      = array_keys($phab_projects);
$phab_users              = get_phab_users($admin);


// Creating the new PhabricatorProject objects.
if (CREATE_PROJECTS) {
	$missing_phab_projects = get_missing_projects($phab_project_names, $ldap_group_names);
	if (count($missing_phab_projects) > 0) {
		echo "\nThe following phabricator projects are going to be created        ...\n";
		foreach ($missing_phab_projects as $missing_phab_project) {
			if (!DRY_RUN) {
				create_phab_project($missing_phab_project, $admin);
			}
			echo $missing_phab_project . ' ';
		}
		echo "\n\n";
		// make a new map, after we created all the new projects.
		$phab_projects = get_phab_projects($admin);
	} else {
		echo "\nNo new projects need to be created                                ...\n\n\n";
	}
} else {
	echo "Skipping phabricator ldap project creation                          ...\n\n\n";
}

// Creating the new PhabricatorUser objects.
if (CREATE_USERS) {
	$missing_phab_users    = get_missing_users(array_keys($phab_users), array_filter(array_keys($ldap_users)));
	if (count($missing_phab_users) > 0) {
		echo "The following phabricator ldap users are going to be created        ...\n";
		foreach ($missing_phab_users as $phab_user) {
			$ldap_user = $ldap_users[$phab_user];
			if (isset($ldap_user['mail'][0])) {
				if (!DRY_RUN and CREATE_PROJECTS) {
					create_phab_user($ldap_user, $admin);
				}
				echo $phab_user . ' ';
			} else {
				continue;
			}
		}
		echo "\n\n";
		// Get all the users again after you added new users.
		$phab_users = get_phab_users($admin);
	} else {
		echo "No new phabricator ldap users need to be created                    ...\n\n\n";
	}
} else {
	echo "Skipping phabricator ldap user creation                             ...\n\n\n";
}

// iterates over each phab_project that matches the ldap_groups then
// it adds edges for adding/removing users to each project.
foreach ($phab_projects as $phab_project_name => $phab_project) {
	print("NAME: " . $phab_project_name . "\n");
	$group_has_members = isset($ldap_groups[$phab_project_name][LDAP_GROUP_MEMBER_ATTR]);

	if ($group_has_members) {
		print("AHAHAH\n");
		$ldap_members = $ldap_groups[$phab_project_name][LDAP_GROUP_MEMBER_ATTR];
		$expected_phab_project_members = array();
		foreach ($ldap_members as $ldap_member) {
			if (isset($phab_users[$ldap_member])) {
				$expected_phab_project_members[$ldap_member] = $phab_users[$ldap_member];
			}
		}
		$edges = get_phab_project_member_edges($admin, $phab_project['object'], $expected_phab_project_members);
		if (isset($edges['add'])) {
			$phab_projects[$phab_project_name]['add'] = $edges['add'];
		}
		if (isset($edges['remove'])) {
			$phab_projects[$phab_project_name]['remove'] = $edges['remove'];
		}
	}
}


// Adds/Removes PhabricatorUsers from projects to match the LDAP group stuff.
$type_member = PhabricatorProjectMemberOfProjectEdgeType::EDGECONST;
$total_edge_changes = 0;
foreach ($phab_projects as $phab_project_name => $value) {
	if (isset($value['add'])) {
		$total_edge_changes += count($value['add']);
	}
	if (isset($value['remove'])) {
		$total_edge_changes += count($value['remove']);
	}
}
if ($total_edge_changes > 0) {
	echo "The following projects will have users added andor removed        ...\n";
} else {
	echo "No new PhabricatorUsers will be added or removed from projects    ...\n";
}
foreach ($phab_projects as $phab_project_name => $value) {
	if (!DRY_RUN) {
		$editor = id(new PhabricatorEdgeEditor());
	}
	$is_add_queue = isset($value['add']) and count($value['add']) > 0;
	$is_rem_queue = isset($value['remove']) and count($value['remove']) > 0;
	if ($is_add_queue or $is_rem_queue) {
		echo $phab_project_name . " will have the following changes:";
	} else {
		continue;
	}
	if (isset($value['remove']) and $is_rem_queue) {
		echo "\nRemoved users from this project: ";
		$remove_users = $value['remove'];
		foreach ($remove_users as $phid => $phuser_obj) {
			echo $phuser_obj->getUsername().' ';
			if (!DRY_RUN and REMOVE_PROJECT_MEMBERS) {
				$editor->removeEdge($value['object']->getPHID(), $type_member, $phid);
			}
		}
	}
	if (isset($value['add']) and $is_add_queue) {
		echo "\nAdded users to this project: ";
		$add_users = $value['add'];
		foreach ($add_users as $phid => $phuser_obj) {
			echo $phuser_obj->getUsername().' ';
			if (!DRY_RUN and ADD_PROJECT_MEMBERS) {
				$editor->addEdge($value['object']->getPHID(), $type_member, $phid);
			}
		}
	}
	echo "\n\n\n";
	if (!DRY_RUN) {
		$editor->save();
	}
}
