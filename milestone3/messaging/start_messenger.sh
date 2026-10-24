#!/bin/bash

echo " "
echo "Starting RabbitMQ Server"
echo "-------------------------------"

sudo -n systemctl start rabbitmq-server

if sudo -n rabbitmqctl await_startup; then
        echo " "
        echo "Current Status: "
        sudo systemctl status rabbitmq-server --no-pager
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
