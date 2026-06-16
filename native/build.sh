#!/usr/bin/env sh
set -eu
DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
cc -O3 -fPIC -shared -o "$DIR/libnexphant_native.so" "$DIR/nexphant_native.c"
