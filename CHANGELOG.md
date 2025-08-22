## upcoming (no specific time table but during 2025) ##
* Feature-specific prunelists to be replaced by feature-specific preservelists, because multiple features could depend on the same file.

## 2025-08-NN ##
* Filter extension is now required.
* Pkgbase support with automatic download.
* Due to having two FreeBSD installation methods now, automatic discovery of tarballs was removed.
* Latest build log file is now symlinked to log/latest.

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
