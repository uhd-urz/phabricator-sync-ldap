<?php

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
// export LDAP_SEARCH_ALL_PEOPLE="ou=People,dc=mgmt,dc=mydomain,dc=net"
// export LDAP_SEARCH_ALL_GROUPS="ou=People,dc=mgmt,dc=mydomain,dc=net"
// export LDAP_URI="ldap://10.0.0.1:389"
//
// You should modify these constants below to configure this script
// for your environment and setup.
define('LDAPDN', getenv('LDAP_USER'));
define('LDAPPASS', getenv('LDAP_PASS'));
define('LDAP_URI', getenv('LDAP_URI'));
define('OU_GROUP', getenv('LDAP_SEARCH_ALL_GROUPS'));
define('OU_PEOPLE', getenv('LDAP_SEARCH_ALL_PEOPLE'));
define('GROUP_NAME_FILTER', 'cn=app.stash*');
define('PEOPLE_NAME_FILTER', 'cn=*');

$root = dirname(__FILE__); // make this a constant?
require_once $root.'/scripts/__init_script__.php';


// This returns an ldap object, which can be used to query ldap.
function get_ldap_connection() {
  $ldapconn = ldap_connect(LDAP_URI);
  ldap_set_option($ldapconn, LDAP_OPT_PROTOCOL_VERSION, 3);
  ldap_set_option($ldapconn, LDAP_OPT_REFERRALS, 0);
  ldap_bind($ldapconn, LDAPDN, LDAPPASS);
  return $ldapconn;
}


function get_ldap_group_data($ldapconn, $filter) {
  $search_results = ldap_search($ldapconn, OU_GROUP, GROUP_NAME_FILTER);
  $groups_array = ldap_get_entries($ldapconn, $search_results);
  $groups_hash = array();
  foreach ($groups_array as $group) {
    $groups_hash[$group['cn'][0]] = $group;
  }
  return $groups_hash;
}


// This return { 'john_doe' => { 'mail' => 'john_doe@mydomain.com', 'uid' => 'john_doe' }, ...  }
function get_ldap_people($ldapconn) {
  $ldap_people_names = array();
  $search_results    = ldap_search($ldapconn, OU_PEOPLE, PEOPLE_NAME_FILTER);
  $ldap_objects      = ldap_get_entries($ldapconn, $search_results);
  foreach ($ldap_objects as $ldap_person) {
    if (isset($ldap_person['mail'][0])) {
      $ldap_people_names[$ldap_person['uid'][0]] = $ldap_person;
    }
  }
  return array_filter($ldap_people_names);
}


// This returns phabricator objects in the following form:
// { 'project_a' => { 'object' => phab_object }, ... }
// the result of this map is cached and used later also
function get_phab_projects($admin) {
  $phab_project_objects = id(new PhabricatorProjectQuery())
    ->setViewer($admin)
    ->needMembers(true)
    ->execute();
  $phab_projects = array();
  foreach ($phab_project_objects as $phab_project) {
    $phab_projects[$phab_project->getName()]['object'] = $phab_project;
  }
  return $phab_projects;
}


// [ 'phab_user_1', 'phab_user_2', 'phab_user_3', ...]
function get_all_phabricator_users($admin) {
  $phab_user_objs = id(new PhabricatorPeopleQuery())
    ->setViewer($admin)
    ->execute();
  return mpull($phab_user_objs, null, 'getUsername');
}


// [ 'uncreated_project_a', 'uncreated_project_b'... ]
function get_uncreated_projects_list($phab_projects, $ldap_groups) {
  return array_filter(array_diff($ldap_groups, $phab_projects));
}


// [ 'uncreated_user_a', 'uncreated_user_b', ... ]
function get_uncreated_users_list($phab_users, $ldap_people_names) {
  return array_filter(array_diff($ldap_people_names, $phab_users));
}


function phab_create_project($admin, $project_name, $admin) {
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
function phab_create_user($ldap_user, $admin) {
  $editor = id(new PhabricatorUserEditor())
    ->setActor($admin);
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
  $editor->createNewUser($phab_user, $phab_user_email);
}


// This will set 'add' and 'rem' keys onto each project's map/cache.
// The ldap_group_data is the master list.
function get_phab_project_member_edges($admin, $phab_project, $phab_users) {
  $users   = mpull(array_values($phab_users), null, 'getUsername');
  $users   = mpull($users, null, 'getUsername');
  $users   = mpull($users, null, 'getPHID');
  $current = $phab_project->getMemberPHIDs();
  if (count($current) > 0) {
    $current_users = id(new PhabricatorPeopleQuery())
    ->setViewer($admin)
    ->withPHIDs($current)
    ->execute();
    $all_users = mpull($users, null, 'getPHID') +
    mpull($current_users, null, 'getPHID');
  } else {
    $all_users = mpull($users, null, 'getPHID');
  }
  $add       = array_diff_key($users, array_fuse($current));
  $rem       = array_diff_key(array_fuse($current), $users);
  $result    = array();
  $result['add'] = array_select_keys($all_users, array_keys($add));
  $result['rem'] = array_select_keys($all_users, array_keys($rem));
  return $result;
}


function create_phab_project_edges() {
  return False;
}

$search_admin = id(new PhabricatorPeopleQuery())
    ->setViewer(PhabricatorUser::getOmnipotentUser())
    ->withUsernames(array('admin'))
    ->execute();
$admin                   = array_values($search_admin)[0];
$ldapconn                = get_ldap_connection();
$ldap_group_data         = get_ldap_group_data($ldapconn, GROUP_NAME_FILTER);
$ldap_group_names        = array_keys($ldap_group_data);
$ldap_people             = get_ldap_people($ldapconn);

$phab_projects_map       = get_phab_projects($admin);
$phab_project_names      = array_keys($phab_projects_map);
$phab_users              = get_all_phabricator_users($admin);
$uncreated_phab_projects = get_uncreated_projects_list($phab_project_names, $ldap_group_names);
$uncreated_phab_users    = get_uncreated_users_list(array_keys($phab_users), array_filter(array_keys($ldap_people)));


// Creating the new PhabricatorProject objects.
if (count($uncreated_phab_projects) > 0) {
  echo "\nThe following phabricator projects are going to be created        ...\n";
  foreach ($uncreated_phab_projects as $uncreated_phab_project) {
    phab_create_project($admin, $uncreated_phab_project, $admin);
    echo $uncreated_phab_project . ' ';
  }
  echo "\n\n";
  // make a new map, after we created all the new projects.
  $phab_projects_map = get_phab_projects($admin);
} else {
  echo "\nNo new projects need to be created                                ...\n\n\n";
}


// Creating the new PhabricatorUser objects.
if (count($uncreated_phab_users) > 0) {
  echo "The following phabricator ldap users are going to be created      ...\n";
  foreach ($uncreated_phab_users as $phab_user) {
    $ldap_user = $ldap_people[$phab_user];
    if (isset($ldap_user['mail'][0])) {
      phab_create_user($ldap_user, $admin);
      echo $phab_user . ' ';
    } else {
      continue;
    }
  }
  echo "\n\n";
  // Get all the users again after you added new users.
  $phab_users = get_all_phabricator_users($admin);
} else {
  echo "No new phabricator ldap users need to be created                  ...\n\n\n";
}


// iterates over each phab_project that matches the ldap_group_data then
// it adds edges for adding/removing users to each project.
foreach ($phab_projects_map as $phab_project_name => $phab_project) {
  $group_has_members = isset($ldap_group_data[$phab_project_name]['memberuid']);
  if ($group_has_members) {
    $ldap_members = $ldap_group_data[$phab_project_name]['memberuid'];
    $project_phab_users = array();
    foreach ($ldap_members as $ldap_member) {
      if (isset($phab_users[$ldap_member])) {
        $project_phab_users[$ldap_member] = $phab_users[$ldap_member];
      }
    }
    $edges = get_phab_project_member_edges($admin, $phab_project['object'], $project_phab_users);
    if (isset($edges['add'])) {
      $phab_projects_map[$phab_project_name]['add'] = $edges['add'];
    }
    if (isset($edges['rem'])) {
      $phab_projects_map[$phab_project_name]['rem'] = $edges['rem'];
    }
  }
}


// Adds/Removes PhabricatorUsers from projects to match the LDAP group stuff.
$type_member = PhabricatorEdgeConfig::TYPE_PROJ_MEMBER;
$total_edge_changes = 0;
foreach ($phab_projects_map as $phab_project_name => $value) {
  $total_edge_changes += count($value['add']);
  $total_edge_changes += count($value['rem']);
}
if ($total_edge_changes > 0) {
  echo "The following projects will have users added andor removed        ...\n";
} else {
  echo "No new PhabricatorUsers will be added or removed from projects    ...\n";
}
foreach ($phab_projects_map as $phab_project_name => $value) {
  $editor = id(new PhabricatorEdgeEditor());
  $is_add_queue = count($value['add']) > 0;
  $is_rem_queue = count($value['rem']) > 0;
  if ($is_add_queue or $is_rem_queue) {
    echo $phab_project_name . " will have the following changes:";
  } else {
    continue;
  }
  if (isset($value['rem']) and $is_rem_queue) {
    echo "\nRemoved users from this project: ";
    $remove_users = $value['rem'];
    foreach ($remove_users as $phid => $phuser_obj) {
      echo $phuser_obj->getUsername().' ';
      $editor->removeEdge($value['object']->getPHID(), $type_member, $phid);
    }
  }
  if (isset($value['add']) and $is_add_queue) {
    echo "\nAdded users to this project: ";
    $add_users = $value['add'];
    foreach ($add_users as $phid => $phuser_obj) {
      echo $phuser_obj->getUsername().' ';
      $editor->addEdge($value['object']->getPHID(), $type_member, $phid);
    }
  }
  echo "\n\n\n";
  $editor->save();
}
