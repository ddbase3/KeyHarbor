#!/bin/sh
set -eu

# Run from the BASE3 application root after applying the new patch.
FILE="plugin/KeyHarbor/test/Display/EncryptionProfilesHeaderSmoke.php"
EXPECTED="98c34b0ef58454fbbbc2f3022be690474e5366700050e61dad91e06c109c0734"

if [ ! -e "$FILE" ]; then
	echo "Legacy smoke test is already absent."
	exit 0
fi

ACTUAL=$(sha256sum "$FILE" | cut -d ' ' -f 1)
if [ "$ACTUAL" != "$EXPECTED" ]; then
	echo "Refusing to remove modified legacy test: $FILE" >&2
	exit 1
fi

rm -- "$FILE"
echo "Removed legacy header smoke test; use KeyHarborAdministrationDisplayTest.php."
