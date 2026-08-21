<?php declare(strict_types=1);
namespace TarBSD\Util;

interface Overlay
{
    const LOADERCONF = <<<LOADERCONF
# adjust your loader settings here

LOADERCONF;

    const FSTAB = <<<FSTAB
# /var/tmp symlinked to /tmp too
tmpfs	/tmp		tmpfs	rw,nosuid,mode=1777,size=32M    0	0
tmpfs	/var/run	tmpfs	rw,nosuid,noexec,size=8M        0	0

FSTAB;

    const RC_CONF = <<<RC_CONF
hostname="tarbsd"

ifconfig_DEFAULT="SYNCDHCP"

# busybox ntpd
tarbsd_ntpd_enable="YES"

# stock ntpd, needs to be enabled in tarbsd.yml first
# ntpd_enable="YES"

# import zpools at boot
# tarbsd_zpools="tank another_tank"

# mount zfs datasets at boot
# zfs_enable="YES"

RC_CONF;

    const RESOLV_CONF = <<<RESOLV_CONF
search lan
nameserver 1.1.1.1
nameserver 8.8.8.8
nameserver 9.9.9.9

RESOLV_CONF;

    const DROPBEAR_INFO = <<<DROPBEAR_INFO

/etc/ssh is the place for your ssh host keys no
matter which ssh program you use. We do some
tricks here to automatically convert openssh's
keys to dropbear's format. This allows you to
change between the two without re-keying clients.

DROPBEAR_INFO;

    const FILES = [
        'boot/loader.conf'  => Overlay::LOADERCONF,
        'etc/fstab'         => Overlay::FSTAB,
        'etc/rc.conf'       => Overlay::RC_CONF,
        'etc/resolv.conf'   => Overlay::RESOLV_CONF,
        'usr/local/etc/dropbear/info' => Overlay::DROPBEAR_INFO
    ];

    const NTPD = <<<NTPD
#!/bin/sh
#
#

# PROVIDE: tarbsd-ntpd
# REQUIRE: DAEMON FILESYSTEMS devfs
# BEFORE:  LOGIN
# KEYWORD: nojail resume shutdown

. /etc/rc.subr

name=tarbsd_ntpd
desc="busybox ntpd"
rcvar=tarbsd_ntpd_enable
load_rc_config \$name
start_cmd="\${name}_start"

tarbsd_ntpd_start()
{
    if [ -f /bin/busybox ]; then
        /bin/busybox ntpd -p pool.ntp.org
    fi
}

run_rc_command "\$1"

NTPD;

    const ZPOOL = <<<ZPOOL
#!/bin/sh

# PROVIDE: tarbsd-zpool
# REQUIRE: mountcritlocal
# BEFORE: zfsbe zfs

. /etc/rc.subr

name=tarbsd_zpool
desc="imports attached zpools"
rcvar=tarbsd_zpool_enable

start_cmd="\${name}_start"

tarbsd_zpool_start()
{
    if [ -f /sbin/zpool ]; then
        for pool in \${tarbsd_zpools}; do
            /sbin/zpool import -f \${pool}
        done
    fi
}

run_rc_command "\$1"

ZPOOL;

    const TARBSDINIT = <<<TARBSDINIT
#!/bin/sh

# PROVIDE: tarbsdinit
# REQUIRE: LOGIN cleanvar
# BEFORE: dropbear

. /etc/rc.subr

name=tarbsdinit
desc="init things specific to tarbsd"
rcvar=tarbsdinit_enable

load_rc_config \$name

start_cmd="\${name}_start"

tarbsdinit_start()
{
    if [ -f /usr/local/bin/dropbearconvert ]; then
        mkdir /var/run/dropbear
        /usr/local/bin/dropbearconvert openssh dropbear /etc/ssh/ssh_host_ed25519_key /var/run/dropbear/dropbear_ed25519_host_key
        /usr/local/bin/dropbearconvert openssh dropbear /etc/ssh/ssh_host_rsa_key /var/run/dropbear/dropbear_rsa_host_key
        /usr/local/bin/dropbearconvert openssh dropbear /etc/ssh/ssh_host_ecdsa_key /var/run/dropbear/dropbear_ecdsa_host_key
    fi
}

run_rc_command "\$1"

TARBSDINIT;

    const RC_FILES = [
        'tarbsd_ntpd'   => Overlay::NTPD,
        'tarbsd_zpool'  => Overlay::ZPOOL,
        'tarbsdinit'    => Overlay::TARBSDINIT
    ];
}
