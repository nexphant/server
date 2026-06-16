#!/usr/bin/env sh
set -eu
DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
cc -O3 -fPIC -shared -o "$DIR/libNEXPHANT_native.so" "$DIR/NEXPHANT_native.c"
