#!/usr/bin/env sh
set -eu
DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
cc -O3 -fPIC -shared -o "$DIR/libnexph_native.so" "$DIR/nexph_native.c"
