## 2026-02-10 ##
* tarBSD motd got broken in previous version and has been fixed.
* Building FreeBSD 14 images for aarch64 works now.

## 2026-02-08 ##
* Aarch64 support. To build an aarch64 image, set "platform: aarch64" in tarbsd.yml.
* Package install step has gotten a speed improvement.
* Work file system will grow automatically to accomodate building bigger images, wrk-init
  command has been deprecated.
* build --release [version] can be shortened to build -r [version]
* Builder doesn't rely on OpenSSH to generate SSH host keys to the image any more and thus,
  it can be run on systems without OpenSSH.
* Building images from tarballs is deprecated. Feature will continue to work for the time
  being, but will be removed eventually.
* Few minor bugs have been fixed.
* [GitHub version only] Application will automatically check if there's a newer version
  available and notify the user.

## 2026-01-25 ##
* More descriptive error messages if the builder happens to have runtime issues.
* Symfony libraries were updated.

## 2025-12-06 ##
* No functional changes, just some micro-optimization for phar app, forward compatibility
  with Symfony 8 libraries and making sure that pkgbase keeps working if there are future
  changes to the domain where base packages are distributed from.

## 2025-11-18 ##
* Base packages for 15 (RELEASE/RC) are downloaded from the new repository run by
  the release engineering team.
  * Patch releases (eg. 15.0-RELEASE-p1) are available immidiatelly after there
    has been an announcement and not sometime during next 24 hours.
* RELEASE can be omitted from "tarbsd build"
  * eg. "tarbsd build --release 15.0" will install the latest -RELEASE or -RC version
    that happens to be in the repository.

## 2025-10-29 ##
* Pigz compression support for kernel. It does the same thing as zopfli, but
  significantly quicker on multi-core processors.

## 2025-10-25 ##
* Port ships with example poudriere and vm-bhyve projects.
  * They can be found from /usr/local/share/examples/tarbsd-builder
* tarBSD rc script can now import zfs pools upon boot.
  * Add tarbsd_zpools variable to rc.conf to use it.
* Small optimizations to image size and build time.
* Several bugs have been fixed.
* BusyBox is off by default for new projects.
* Default SSH program for new projects is now OpenSSH.
* A small facelift, the app looks better now, especially on darker terminals.

## 2025-09-28 ##
* Several issues with FreeBSD 15 have been fixed.
* OpenSSL, certctl, makefs and truncate are now included in BusyBox builds.
* Logic for selecting base packages has been rewritten. The new implementation
  should be less prone to unwanted surprises if there are changes to the base
  package set during FreeBSD 15 release process.

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
