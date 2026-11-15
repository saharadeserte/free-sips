| Queue | Exchange / key | Producer | Consumer | Declared by |
| --- | --- | --- | --- | --- |
| `q_auth_requests` | default exchange, key = queue name | fe server.js | befe AuthConsumer | befe and fe |
| `bedb_exchange` → `q_bedb_rpc` | bedb_exchange / bedb.rpc | befe UserStore | worker.php | worker.php |
| `q_central_logging` | default exchange | fe server.js | worker.php | fe and worker.php |
| reply queues | default exchange, key = reply_to | the consumer | the requester | the requester (temporary) |