<?php declare(strict_types=1);
namespace TarBSD\Util;

interface Strs
{
    const TARBSD_YML = <<<TARBSD_YML
root_pwhash: null
root_sshkey: null
backup: true
busybox: false
ssh: openssh
platform: amd64
features:
    zfs: false
    bsdinstall: false
    geli: false
    pf: true
    ipfw: false
    wireguard: false
    wifi: false
    bhyve: false
    jails: false
    ntpd: false
    rescue: false
    locales: false
modules:
    early:
    late:
        - ipmi
packages:
    - tmux
    - nano
    - htop
TARBSD_YML;

    const KEYS_YML = <<<KEYS_YML
pkgbase-15:
  trusted:
    awskms-15:
      function: sha256
      fingerprint: 1d7b45d20fa8d6ed26f9b4a13ac81a6b5df860b9fe644d07b87e92298ba72595
    backup-signing-15:
      function: sha256
      fingerprint: 56a77bdcb6c3cf7984729c6138bd5617c24aa0d466b3b604c96205b2c5629f3c
KEYS_YML;

    const MOTD = <<<MOTD
              ,       ,
             /(       )`
             \ \__   / |
             /- _ `-/  '
            (/\/ \ \   /\    _             ____   _____ _____ 
            / /   | `    \  | |           |  _ \ / ____|  __ \
            O O   )      |  | |_ __ _ _ __| |_) | (___ | |  | |
            `-^--'`<     '  | __/ _` | '__|  _ < \___ \| |  | |
           (_.)  _ )    /   | || (_| | |  | |_) |____) | |__| |
            `.___/`    /     \__\__,_|_|  |____/|_____/|_____/
              `-----' /
 <----.     __ / __   \   
 <----|====O)))==) \) /====
 <----'    `--' `.__,' \
              |         |
               \       / 
           ____( (_   / \______
         ,'  ,----'   |        \
         `--{__________)       \/

MOTD;

    const PRUNELIST = <<<PRUNELIST
# based on mfsbsd's prunelist, some additions and removals
# https://github.com/mmatuska/mfsbsd
usr/bin/c++
usr/bin/c++filt
usr/bin/g++
usr/bin/c89
usr/bin/c99
usr/bin/CC
usr/bin/cc
usr/bin/clang*
usr/bin/cpp
usr/bin/gcc
usr/bin/yacc
usr/bin/f77
usr/bin/byacc
usr/bin/addr2line
usr/bin/ar
usr/bin/gnu-ar
usr/bin/gnu-ranlib
usr/bin/as
usr/bin/gasp
usr/bin/gcov
usr/bin/gdb
usr/bin/gdbreplay
usr/bin/kyua
usr/bin/ld
usr/bin/ld.bfd
usr/bin/ld.lld
usr/bin/ll*
usr/bin/nm
usr/bin/objcopy
usr/bin/objdump
usr/bin/ranlib
usr/bin/readelf
usr/bin/size
usr/bin/strip
usr/bin/gdbtui
usr/bin/kgdb
usr/games
usr/include
usr/lib32
usr/lib/*.a
usr/lib/clang
usr/libexec/cc1
usr/libexec/cc1obj
usr/libexec/cc1plus
usr/libexec/f771
usr/sbin/local-unbound*
usr/lib/libprivateunbound*
usr/share/dict
usr/share/doc
usr/share/examples
usr/share/info
usr/share/games
usr/share/man
usr/share/openssl
usr/share/nls
usr/tests
usr/sbin/bsdconfig
usr/sbin/pkg
var/db/etcupdate
usr/local/include
usr/local/share/doc
usr/local/share/man
usr/local/share/examples
usr/local/lib/*.a
usr/local/lib/*/*.a
usr/local/lib/*/*/*.a
usr/local/lib/*/*/*/*.a
usr/local/lib/*/*/*/*/*.a
usr/local/lib/*/*/*/*/*/*.a
sbin/bectl
bin/ed
bin/red
usr/share/i18n
PRUNELIST;

    const PRUNELIST_OPENSSH = <<<PRUNELIST_OPENSSH
etc/ssh/moduli
etc/rc.d/sshd
usr/bin/scp
usr/bin/sftp
usr/bin/slogin
usr/bin/ssh*
usr/sbin/sshd
usr/lib/*ssh*
usr/libexec/sftp-server
usr/libexec/ssh*
usr/lib/*krb*
usr/lib/libgss*
usr/lib/libhx509*
usr/lib/libasn1*
usr/lib/libkadm*
usr/lib/libk5*
usr/lib/libkrad
usr/lib/libprivateldns*
usr/lib/libprivatefido2*
usr/lib/libprivatecbor*
usr/lib/libverto*
usr/lib/libcom_*
PRUNELIST_OPENSSH;

    const BASE_PKGS = <<<BASE_PKGS
set-minimal
([a-z]+)-(tools|data|lib)
(bsd|lib)([0-9a-z_]+)
firmware-([0-9a-z_]+)
acct
acpi
apm
at
autofs
bhyve
blocklist
bootloader
bsnmp
bzip2
caroot
ccdconfig
certctl
clibs
clibs-dev
cron
csh
devd
devmatch
dhclient
dma
dpv
dwatch
fetch
ftp
ftpd
fwget
geom
inetd
ipf
ipfw
iscsi
jail
kerberos
locales
mtree
natd
netmap
newsyslog
nfs
ntp
nuageinit
openssl
periodic
pf
pkg-bootstrap
powerd
ppp
quotacheck
rc
rcmds
rescue
resolvconf
runtime
smbutils
ssh
syslogd
tcpd
ufs
utilities
vi
wpa
yp
zfs
zoneinfo
BASE_PKGS;

    const BUSYBOX = <<<BUSYBOX
[
[[
addgroup
ar
arch
ascii
ash
awk
base32
base64
basename
bc
bunzip2
bzcat
bzip2
cal
cat
chat
chgrp
chmod
chown
chpst
chroot
cksum
clear
cmp
comm
cp
cpio
crc32
crond
crontab
cttyhack
cut
date
dc
dd
delgroup
diff
dirname
dnsd
dnsdomainname
dos2unix
dpkg
dpkg-deb
du
echo
ed
egrep
env
envdir
envuidgid
expand
expr
factor
fakeidentd
fallocate
false
fatattr
fgrep
find
flock
fold
fsync
ftpd
ftpget
ftpput
getopt
grep
groups
gunzip
gzip
halt
hd
head
hexdump
hexedit
hostid
hostname
httpd
hush
id
inetd
install
iostat
ipcalc
kill
killall
killall5
klogd
less
link
ln
logger
logname
logread
lpq
lpr
ls
lzcat
lzma
lzop
makemime
man
md5sum
microcom
mim
mkdir
mkfifo
mknod
mktemp
more
mpstat
mv
nc
nice
nl
nmeter
nohup
nologin
nslookup
ntpd
nuke
od
paste
patch
pgrep
pidof
ping
ping6
pipe_progress
pkill
pmap
popmaildir
poweroff
printenv
printf
ps
pscan
pwd
pwdx
readlink
readprofile
realpath
reboot
reformime
renice
reset
resize
resume
rev
rm
rmdir
rpm
rpm2cpio
run-parts
runsv
runsvdir
script
scriptreplay
sed
sendmail
seq
setsid
setuidgid
sh
sha1sum
sha256sum
sha3sum
sha512sum
shred
shuf
sleep
smemcap
softlimit
sort
split
ssl_client
stat
strings
stty
su
sulogin
sum
sv
svc
svlogd
svok
sync
syslogd
tac
tail
tar
tcpsvd
tee
telnet
telnetd
test
tftp
tftpd
timeout
top
touch
tr
traceroute
traceroute6
tree
true
truncate
ts
tsort
tty
ttysize
uname
uncompress
unexpand
uniq
unix2dos
unlink
unlzma
unxz
unzip
users
usleep
uudecode
uuencode
vi
volname
w
wall
watch
wc
wget
which
who
whoami
whois
xargs
xxd
xz
xzcat
yes
zcat
BUSYBOX;
}
