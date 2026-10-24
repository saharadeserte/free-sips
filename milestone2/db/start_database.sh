#!/bin/bash
export PATH="$PATH:/usr/share/dotnet:/usr/bin"

# db/start_database.sh
cd "$(dirname "$0")" || exit 1


echo "Starting MariaDb";
sudo -n systemctl start mariadb

#php listener.php &
#php test_Db.php &
echo "testing the Amqp Consumer";
nohup php amqp_comsumer.php > /tmp/amqp.log 2>&1 &

echo "All services are currently running"
