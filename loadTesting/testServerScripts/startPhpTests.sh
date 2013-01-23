#!/bin/bash

if [ $# -lt 3 ]; then
	echo "Usage: $0 <start-test-num> <end-test-num> <cmd>"
	exit 1
fi

for (( i=$1; i<=$2; i++ )); do
	cmd="$3 --test-num=$i"
	eval "$cmd < /dev/null 2>&1 > \"logs/${i}.out\" &"
done
