#!/bin/bash

tar xzvf files.tgz
mkdir logs output cookies
if [ $# -lt 1 ] || [ "$1" != '--local' ]; then
	
	# Create in-memory filesystems
	if [ ! -e memfs ]; then
		mkdir memfs
		sudo mount -t tmpfs -o size=8g tmpfs memfs
	fi
	cd memfs
	mkdir logs output cookies
	cd ..
	rm -rf logs output cookies
	ln -s memfs/logs logs
	ln -s memfs/output output
	ln -s memfs/cookies cookies
	
	sudo yum -y install php php-xml
	sudo sed -i -e 's/;date.timezone.*$/date.timezone = America\/New_York/g' /etc/php.ini
	
	# Remove old settings from /etc/security/limits.conf
	sudo cat /etc/security/limits.conf | grep -v '# Load Testing' | sudo tee /etc/security/limits.conf
	
	# Add new settings to /etc/security/limits.conf
	echo 'ec2-user	soft	nofile	65536 # Load Testing' | sudo tee -a /etc/security/limits.conf
	echo 'ec2-user	hard	nofile	65536 # Load Testing' | sudo tee -a /etc/security/limits.conf
	echo 'ec2-user	soft	nproc	65536 # Load Testing' | sudo tee -a /etc/security/limits.conf
	echo 'ec2-user	hard	nproc	65536 # Load Testing' | sudo tee -a /etc/security/limits.conf
	
	# Remove any old setting from /etc/sysctl.conf
	sudo cat /etc/sysctl.conf | grep -v '# Load Testing' | sudo tee /etc/sysctl.conf
	
	# Add new settings to /etc/sysctl.conf
	echo 'net.ipv4.tcp_max_syn_backlog = 65536 # Load Testing' | sudo tee -a /etc/sysctl.conf
	echo 'net.ipv4.ip_local_port_range = 2048 64000 # Load Testing' | sudo tee -a /etc/sysctl.conf
	echo 'net.core.somaxconn = 65536 # Load Testing' | sudo tee -a /etc/sysctl.conf
	echo 'net.core.netdev_max_backlog = 4000 # Load Testing' | sudo tee -a /etc/sysctl.conf
	sudo sysctl -p /etc/sysctl.conf

fi;
echo 1 > '.setupcomplete'