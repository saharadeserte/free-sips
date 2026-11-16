#!/bin/bash
#change to push/commit
echo " "
echo "Starting RabbitMQ Server"
echo "-------------------------------"

if systemctl is-active --quiet rabbitmq-server; then
        echo " "
        echo "--------------------------------------------------"
        echo "RabbitMQ Server already running. Skipping startup."
        echo "--------------------------------------------------"
        exit 0
fi

sudo -n systemctl start rabbitmq-server

if sudo -n rabbitmqctl await_startup; then
        echo " "
        echo "Current Status: "
        sudo -n systemctl status rabbitmq-server --no-pager
        echo " "
        echo "----------------------------------"
        echo "RabbitMQ Server is up and Running!"
else
        echo " "
        echo "----------------------------------"
        echo "RabbitMQ Server failed to start"
        echo "----------------------------------"
        echo "Check logs"
        sudo -n journalctl -u rabbitmq-server -n 50 --no-pager
        exit 1
fi
