===============================================================
WHM Backup Solutions - Fork V1.7
===============================================================

This is a maintained fork of WHM Backup Solutions by Peter Kelly.

	Original upstream: https://bitbucket.org/peterk1992/whm-backup-solutions
	Original project:  https://whmbackup.solutions  (domain no longer resolves)
	This fork:         https://github.com/wickachan/whm-backup-solutions

The upstream project was last updated on 2021-12-30 (V1.6) and no longer
works against current cPanel releases. This fork keeps it running.

===============================================================
About This Fork
===============================================================
Licensed GNU GPL-3.0-or-later, unchanged from upstream. All upstream
history and branches are preserved in this repository.

Changes in V1.7 (all verified against a live cPanel 11.138.0.28 server):

  1. API requests were rejected by cPanel 11.138
     resources/xmlapi/xmlapi.php built the POST body and set Content-Length
     but never passed it to curl, so every call went out bodiless. cPanel
     11.138 answers those with HTTP 400 and a login page, which surfaced as
     the misleading error "'basic-whm-functions' Privileges Not Granted".
     The API token was never the problem.

  2. Failed backups were logged as successes
     backup_accounts() ended its "UAPI" block with an unconditional return,
     leaving the entire legacy API1 branch as dead code. Current cPanel
     versions take that dead branch, so every result fell through to a
     hard-coded error "0". A refused backup was reported as
     "Backup Initiated" and the account was silently never backed up.

  3. The per-account log reported an error for every account
     whmbackup.php logged "ERROR:" outside any condition, so each account
     produced an error line whether it succeeded or not.

  4. Notification email now works on CageFS hosts
     PHP mail() cannot work where the account is confined to a CageFS jail:
     the sendmail wrapper cannot read /etc/domainusers and returns false.
     email_log() now sends over SMTP (STARTTLS + AUTH LOGIN) when an
     smtp_config.php is present, and otherwise behaves exactly as before.

===============================================================
Upgrading From Upstream V1.6
===============================================================
Replace these files, keeping your existing config.php:

	resources/functions.php
	resources/xmlapi/xmlapi.php
	whmbackup.php

If PHP mail() works on your server, no further action is needed.

If it does not, and runs log "Unable To Send Notification Email, An Error
Occured While Trying To Send The Email Using PHP Internal Mail Function":

	cp smtp_config.php.new smtp_config.php
	chmod 600 smtp_config.php
	# then fill in your SMTP server and mailbox credentials

Deleting smtp_config.php reverts to the original mail() behaviour.

===============================================================
Script Files
===============================================================
	whmbackup.php
	ftp_retention.php
	config.php.new
	secure-config.php.new
	smtp_config.php.new
	LICENSE.txt
	index.html
	resources/functions.php
	resources/xmlapi/xmlapi.php
	resources/index.html
	temp/
	temp/index.html
	logs/
	logs/index.html
===============================================================

===============================================================
Installation
===============================================================
A quick summary of installation instructions is listed below.
For more detailed instructions visit https://whmbackup.solutions/documentation/

1. Download the latest version from https://whmbackup.solutions.

2. Extract files and rename config.php.new to config.php

3. Enter details into config.php.

4. Upload all files outside of public web directory.

5. Setup Cron Jobs
	- Generate Backup List
		This cronjob should run as frequent as you want
		backups taking e.g. once a day/week/month etc.

		Command: php -q /home/user/whmbackupsolutions/whmbackup.php generate

		(Please note if you have register_argv_argc disabled
		on your server this will not work. A work around is
		available at https://whmbackup.solutions/documentation/)

	- Run Account Backup
		This cronjob should run every 5, 10 or 15 minutes.
		We recommend setting this to every 5 minutes but
		if there are accounts larger than 2GB then the time
		between runs should be increased to reduce server load.

		Command: php -q /home/user/whmbackupsolutions/whmbackup.php

	- Run FTP Backup Retention
		This cronjob should run shortly after every backup run.

		Command: php -q /home/user/whmbackupsolutions/ftp_retention.php

===============================================================

===============================================================
Developer
===============================================================
WHM Backup Solutions was developed and maintained by Peter Kelly
<peter@whmbackup.solutions>.
If you are struggling to install the original script, experience
error messages or have suggestions to improve the script please
get in contact via the facebook page https://www.facebook.com/whmbackupsolutions/
or email him at peter@whmbackup.solutions.

This fork is maintained at https://github.com/wickachan/whm-backup-solutions
===============================================================

===============================================================
License
===============================================================
This script is licensed under GNU GPL-3.0-or-later, as was upstream.
See LICENSE.txt for the full text.
===============================================================
