## upcoming (most notable things, no specific time table but during 2025) ##
* Feature-specific prunelists to be replaced by feature-specific preservelists, because multiple features could depend on the same file.
* Pkgbase support (needed for FreeBSD 15). Possibly with automatic download.

## 2025-08-17 ##
* Recue typo has been fixed.
* Self-update command has been refactored.
* New wrk-init and wrk-destroy commands.
* Show app version rather than a build time.
* HTTP library was swapped from Guzzle to Symfony HTTP Client.

## 2025-08-12 ##
* /rescue is now a feature
* Log file compression was disabled. This might become a configuration option at some point in the future.
* Phar app isn't built in /tmp when building through ports.
* Diagnose command, paste output of this to possible bug reports.

## 2025-08-07 ##
* strict requirement for mbstring or iconv was dropped

## 2025-08-06 ##
* verbose output written to a log file unless requested to console
* display all PHP warnings
* preparations for a ports/pkg distribution at some point in the future

## 2025-07-18 ##
* freebsd-update after release extraction
* fix date formatting in self-update
* various code changes without expected changes to functionality
