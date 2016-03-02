# Phabricator Sync LDAP

This script syncs LDAP groups with Phabricator projects.

The Phabricator projects need to be specifically marked and only those that are marked will be synced with the specified LDAP group. The Phabricator users need to be of a specific AccountType -- all others will not be touched by the script.

Once [T3980](https://secure.phabricator.com/T3980) is implemented, there is no need for this duct tape anymore.

## Warning!

While the script takes great care to not destroy anything and just work on specifically defined subsets of the Phabricator users and projects, and it should adhere to the restrictions placed upon the configured administrative user, there may still be bugs, either in Phabricator or this script.

Thus it is highly recommended to first try this script on a development instance of Phabricator and only afterwards use it in production.

You do keep backups, don't you?

## Setup

### CustomField

To mark projects to be synced, we [add a CustomField](https://secure.phabricator.com/book/phabricator/article/custom_fields/) to those projects, naming the LDAP DN of the group to sync with:

```json
{
  "my-org:ldap:dn": {
    "name": "LDAP DN",
    "type": "text",
    "caption": "DN of the LDAP object to sync with (if any).",
    "required": false,
    "disabled": false,
    "edit": true,
    "view": false
  }
}
```

Change the `my-org` prefix to something related to your organisation, so as to prevent name clashes with other extensions!

After you added this CustomField, you should populate it for the projects you want to sync.

### Adjust config template

Next, copy the config template to its actual location:

```sh
cp phabricator-sync-ldap.cfg.default phabricator-sync-ldap.cfg
```

Adjust the config to your needs and setup:

* Debugging:
  - `DEBUG`: Be verbose on stdout?
  - `DEBUG_MAILTO`: Where to send a copy of everything that was printed to stdout? This may be unset, which results in no mail being sent.
  - `DEBUG_MAILFROM`: Which e-mail address to use in `From:` when sending emails? This may be unset, which results in no mail being sent.
* Actions:
  - `DRY_RUN`: Only print what would be done, but don't actually do it.
  - `CREATE_USERS` (not implemented): Shall users be created from those found in LDAP?
  - `DELETE_USERS` (not implemented): Shall users be disabled, if they are no longer to be found in LDAP?
  - `CREATE_PROJECTS` (not implemented): Shall projects be created from those found in LDAP?
  - `DELETE_PROJECTS` (not implemented): Shall projects be archived, if they are no longer to be found in LDAP?
  - `ADD_PROJECT_MEMBERS`: Do you want the script to add members to projects if additional members are defined in LDAP?
  - `REMOVE_PROJECT_MEMBERS`: Do you want the script to remove members from projects, if they are not members of the LDAP group?
* LDAP Connection:
  - `LDAP_BASE`: The base DN in your LDAP tree.
  - `LDAP_USER_SUBTREE`: In which subtree (relative to base DN) are the users stored?
  - `LDAP_GROUP_SUBTREE`: In which subtree (relative to base DN) are groups stored?
* User:
  - `LDAP_USER_FILTER`: How to find LDAP user objects?
  - `LDAP_USER_NAME_ATTR`: Which attribute represents the display name in your LDAP setup?
  - `LDAP_USER_MAIL_ATTR` (unused): Which attribute contains the e-mail address of this user?
  - `LDAP_USER_GROUP_ATTR` (unused): Which attribute points to this user's LDAP group?
  - `LDAP_USER_GROUP_TRGT` (unused): What is the target attribute of the group attribute referenced by `GROUP_ATTR`? E.g. if `memberOf` holds the LDAP group DN, this should be `dn`. If it contained the common name, it would be `cn`.
* Group:
  - `LDAP_GROUP_FILTER`: How to find LDAP group objects?
  - `LDAP_GROUP_NAME_ATTR`: Which attribute represents the display name in your LDAP setup?
  - `LDAP_GROUP_MEMBER_ATTR`: Which attribute points to this group's members?
  - `LDAP_GROUP_MEMBER_TRGT` (unused): What is the target attribute of the member attribute referenced by `MEMBER_ATTR`?
* Phabricator:
  - `PHAB_ADMIN_USERNAME`: The administrator user to impersonate.
  - `PHAB_PROJECT_LDAP_DN_FIELD`: What is the fully qualified name of the CustomField you created in step (1)? Be aware that the name you defined in step (1) is additionally prefixed with `std:project:`!
  - `PHAB_USER_LDAP_DN_FIELD` (unused): What is the fully qualified name of the CustomField used to detect users managed by LDAP?
  - `PHAB_USER_ACCOUNT_TYPE`: What is the AccountType of the users you want to manage? For the built-in LDAP support this is `ldap`, for users managed by [the RemoteUser Phabricator extension](https://github.com/uhd-urz/phabricator-extensions-remoteuser) this is `RemoteUser`. Have a look at `getAdapterType()` in `libphutil/src/auth/Phutil*AuthAdapter.php` for other possible values.
  - `PHAB_PROTECTED_USERS`: Which usernames shall the script never touch? E.g. put the names of your admins here.

### Run

Define environment variables corresponding to the LDAP connection and run the script:

```sh
env \
	LDAP_URI="ldap://ldap.example.com/" \
	LDAP_BINDDN="cn=binduser,ou=ldapusers,..." \
	LDAP_BINDPW="..." \
	./phabricator-sync-ldap.php
```

For security reasons, `LDAP_URI`, `LDAP_BINDDN` and `LDAP_BINDPW` are not stored in the config file, as that is stored in the script directory and might easily be world readable.

If you do not set `LDAP_BINDDN` and `LDAP_BINDPW`, an anonymous bind will be attempted.

### Cron

Finally, you could set up a cron-job.
