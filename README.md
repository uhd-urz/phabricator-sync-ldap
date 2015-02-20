This is a script that syncs LDAP groups / users, with pabricator projects.

This is some glue and duct tape until https://secure.phabricator.com/T3980 is completed. Once T3980 is completed, there is no longer a need for this script.

Warning, this script will do DB writes/changes (creating projects, users, and modifying projects).
I highly recommend making a dev instance of phabricator and playing with that, instead of production.

These are the steps when you run this command:

1. Query ldap ouGroups matching cn=app.stash*, find the cn results, then create a PhabricatorProject for each result if it doesn't already exist.
2. Query ldap ouPeople, find the uid results, then create a PhabricatorUser for each result if it doesn't already exist.
3. Take the results from each ouGroup, check the 'memberuid' array, add any of those members to the corresponding PhabricatorProject. Remove any members from this PhabricatorProject that aren't in the memberuid array from the Group attribute.


Intstructions for installing:

1. Enable LDAP in Phabricator and make sure it works.
2. Disable username/password registration in Phabricator
3. Set all the bash environment variables and modify any constants at the top of the script.
4. Put the script in the root phabricator directory.
5. Setup a cronjob to run every x minutes.
