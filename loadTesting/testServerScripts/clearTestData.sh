#!/bin/bash

# Clear old data
# Logs
find . -not -empty -name logs | grep logs > /dev/null
if [ $? -eq 0 ]; then
	rm -r logs/*
fi
# Output
find . -not -empty -name output | grep output > /dev/null
if [ $? -eq 0 ]; then
	rm -r output/*
fi
# Cookies
find . -not -empty -name cookies | grep cookies > /dev/null
if [ $? -eq 0 ]; then
	rm -r cookies/*
fi

# Clear old stats
if [ -e runningStats.dat ]; then
	rm runningStats.dat
fi
