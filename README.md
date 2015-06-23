This script syncs LDAP users and groups with Phabricator users and projects.

Once [T3980](https://secure.phabricator.com/T3980) is implemented, there is no need for this duct tape anymore.

# Warning!

This script will do DB writes/changes (creating projects, users, and modifying projects).
I highly recommend making a dev instance of phabricator and playing with that, instead of production.

The following sections are outdated and need to be rewritten!
