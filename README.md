https://github.com/user-attachments/assets/a1e2b5e0-675c-4bcf-8ad0-2099b53011ab

tarBSD is a minimal (well, depends on chosen features and packages) FreeBSD image that boots to memory. Most of it is stored in a tar archive mounted at /usr. tarBSD is not a distribution unto itself. Instead, this repository gives you a tool to build your own version of it.

Because most of tarBSD is in a tightly compressed ([zstd-19](https://github.com/facebook/zstd)) tar archive, which is mounted rather than extracted at boot, it doesn't need nearly as much ram as traditional mfs images.

## Possible use cases for tarBSD ##
* router
* NAS
* virtualization host
* all the above in a one box
* a remote FreeBSD installer with SSH

## Installing the builder tool ##
### pkg/ports ###
There are several flavoured packages available. If you have other PHP packages in your system, choose the package name suffix according to them. Corresponding port can be found from sysutils/tarbsd-builder in the ports tree.
```
pkg install tarbsd-builder-php84
```
### GitHub release ###
Download it from the [releases](https://github.com/pavetheway91/tarbsd/releases) page. In order to run it, you'll need an existing FreeBSD system with PHP >= 8.2 along with some extensions. GitHub version can be updated with the self-update command.
```
# make tarbsd builder executable
# and move it to /usr/local/bin
chmod +x tarbsd
mv tarbsd /usr/local/bin/tarbsd

# dependencies
pkg install php84-phar php84-zlib php84-filter php84-pcntl php84-mbstring

# (optional) pigz for better kernel compression
pkg install pigz
```

## Usage ##
Start by creating a project directory and using tarbsd bootstrap command there.

```
mkdir myproject
cd myproject
tarbsd bootstrap
```
It'll ask few questions, create a configuration file as well as an overlay directory, which contents will be recursively copied to the image. You'll likely want to edit tarbsd.yml and tarbsd/etc/rc.conf at least.

### Building the image ###
```
# RELEASE version
tarbsd build --release 15.0

# LATEST here could mean STABLE or CURRENT depending on version
tarbsd build --release 15-LATEST
```

First build will take longer, but subsequent ones are quicker. It uses in-memory zfs pool for building, so everyting is snappy and there's no unnecesary writes to your storage medium. ZFS also allows builder to use snapshots to restore the image to a an earlier state before each build.

When in hurry, pass --quick option to the builder. You'll get the image quicker, but it will be bigger and require more memory to boot. For small images, size difference might not be huge, but it gets bigger as /usr gets bigger. Useful for builds that are intended to be just prototypes anyway.
```
tarbsd build --release 15.0 --quick
```

Raw .img (for bhyve, kvm and real computers) is always generated. If you have qemu-tools installed, it can also be converted to all sorts of random formats.
```
tarbsd build --release 15.0 qcow qcow2 vdi vmdk vhdx vpc parallels
```

Verbose output doesn't quite show every single little detail yet, but if you like tar -v and pkg install being streamed to your console, you can have them.
```
tarbsd build --release 15.0 -v
```

## tarbsd.yml options ##
### root_pwhash ###

### root_sshkey ###

### backup ###
Backup tarbsd.yml as well as the overlay directory inside the image. If you loose the computer, which created the image, you've got backup inside the image itself assuming it runs on another computer and you haven't lost that one too.

### busybox ###
Replaces many applications with [busybox](https://en.wikipedia.org/wiki/BusyBox). Might break some shell scripts and some commands might not behave exactly in a way you're used to.

### ssh (dropbear|openssh|null) ###
Dropbear is slightly smaller. tarBSD does some tricks here to share the host keys between the two, so you can switch easily without re-keying clients. No OpenSSH also means no base kerberos.

### platform ###
Amd64 (default) or aarch64.

### features ###
Things such as ZFS, bhyve and wireguard etc that are only needed by some and thus, are opt-in. Depending on feature, it will include relevant kernel modules, userland tools and sometimes packages. Please, suggest new ones (or send pr) if you think something is missing.

### modules ###
Two lists (early and late) of kernel modules to be included in the image. Early modules are loaded immediately upon boot, while the late ones will be available later.

### packages ###
List of packages to be installed.

## Other miscellaneous things ##
* tarBSD lives in memory. If you need non-volatile storage, you need to mount it. If you mount something in /usr (which is read-only), make a corressponding empty directory to tarbsd/usr, so it can be mounted.
* Because the image is built using in-memory file system, the system running the builder needs to have adequate amount of usable memory. Old Raspberry pis likely will struggle.
* Many applications might be missing, but libraries are mostly there. Vast majority of packages should just work.
* tarBSD requires [tarfs](https://man.freebsd.org/cgi/man.cgi?tarfs(5)), which was introduced in 14.2. Older releases are not supported.
* Builder will automatically add fstab line for following pseudo filesystems if the kernel module is present either through a feature or manual include:
  * procfs
  * fdescfs
  * linprocfs (automatically included in busybox builds)
  * linsysfs
* SSH is on by default unless there's no SSH program. You can disable it by setting sshd_enabled="NO" or dropbear_enable="NO" in etc/rc.conf.
* Base packages and compressed kernels are cached at /var/cache/tarbsd and this cache is shared across all tarbsd projects you might have. Other things such as port packages are cached locally at the project up until next boot.
* Random reads across various places in /usr might be slightly slow due to obvious reason. Depending on applications, this might or might not be noticeable. Usually however, services are started at boot and that's it, so I guess this doesn't matter much for most. There are some tunables in tar, which might help, but I haven't researched them extremely closely yet.

## Contributing ##
There's a compiler in the stubs directory. It spits out the executable, which is a [phar archive](https://www.php.net/manual/en/intro.phar.php). During development, you can just require vendor/autoload.php, create TarBSD\App and run that, but do at least occasional testing with a phar app too.

If you're not familiar with Symfony components, [here's the docs](https://symfony.com/doc/current/index.html). Relevant parts here are console, process, filesystem and finder.

Builder's predecessor was a shell script and there might be some random leftovers of it in the code. Going forward, file access happens in PHP and not PHP invoking /bin/cp, /bin/rm and /bin/ln. Obvious exceptions to this are cases where tar, makefs or something of that sort is needed.