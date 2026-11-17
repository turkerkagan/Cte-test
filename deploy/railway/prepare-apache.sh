#!/bin/sh
set -eu

# mod_php requires prefork. Normalize the enabled modules at image build AND
# container startup so an old/conflicting MPM cannot prevent Apache from booting.
rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf
a2enmod mpm_prefork rewrite headers

# Fail with Apache's actual configuration error before starting Laravel jobs.
apache2ctl configtest
modules="$(apache2ctl -M)"
printf '%s\n' "$modules" | grep -q 'mpm_prefork_module'
if printf '%s\n' "$modules" | grep -Eq 'mpm_(event|worker)_module'; then
    echo 'A conflicting Apache MPM is still loaded.' >&2
    exit 1
fi
echo 'CTE Apache configuration OK: mpm_prefork.'
