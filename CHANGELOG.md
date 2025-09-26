## 2025-09-26 ##
* Pkgbase implementation has been refactored and it should work with FreeBSD 15 too.
	* FreeBSD 15 can be tested with --release 15-LATEST. LATEST here could mean
	  STABLE, RC, BETA, ALPHA, or CURRENT depending on version.
* Stale base packages are cleaned periodically from the cache directory /var/cache/tarbsd.
* "Installing packages" step shows if it's downloading or actually installing at the moment.
* Progress indicator spins at "compressing mfs image" step.

## 2025-08-27 ##
* "Installing packages" step might have failed on some systems and this has been fixed now.
* A deprecation notice on PHP85 has been fixed.

## 2025-08-24 ##
* Filter extension is now required.
* Pkgbase support with automatic download.
* Due to having two FreeBSD installation methods now, automatic discovery of tarballs was removed.
* Latest build log file is now symlinked to log/latest.
* Log rotation (defaults to 10). Can be configured in a new /usr/local/etc/tarbsd.conf, which 
  will also house other pieces of application (rather than a project) configuration in future releases.

## 2025-08-17 ##
* Recue typo has been fixed.
* Self-update command has been refactored.
* New wrk-init and wrk-destroy commands.
* Show app version rather than a build time.
* HTTP library was swapped from Guzzle to Symfony HTTP Client.
* The first release through ports/pkg.

## 2025-08-12 ##
* /rescue is now a feature.
* Log file compression was disabled. This might become a configuration option at some point in the future.
* Phar app isn't built in /tmp when building through ports.
* Diagnose command, paste output of this to possible bug reports.

## 2025-08-07 ##
* Strict requirement for mbstring or iconv was dropped.

## 2025-08-06 ##
* Verbose output written to a log file unless requested to console.
* Display all PHP warnings.
* Preparations for a ports/pkg distribution at some point in the future.

## 2025-07-18 ##
* freebsd-update after release extraction.
* Fix date formatting in self-update.
* Various code changes without expected changes to functionality.
