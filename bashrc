# .bashrc

# User specific aliases and functions

alias rm='rm -i'
alias cp='cp -i'
alias mv='mv -i'

stty erase ^H
setenforce 0

# Source global definitions
if [ -f /etc/bashrc ]; then
	. /etc/bashrc
fi
 
if [ -f /opt/aciucs/B2G_VM_Config/.configured-ip ]; then
	echo "B2G IP Configuration File Already Exists"
	cd /opt/aciucs
else
	echo "Cannot find the B2G IP initialization file, running setup script..."
	cd /opt/aciucs/B2G_VM_Config
	./setup
	cd ..
fi

