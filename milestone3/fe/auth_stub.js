// Temporary stand-in for the backend auth consumer. Delete once the real backend is live.

// * Run it with node auth_stub.js in one terminal and npm start in another. 
// * Then go to http://localhost:7812/. You should land on the login page, be able to register, 
// * log in and reach the landing page, refresh to confirm the session persists, and log out to return to login.
const amqp = require('amqplib');
const crypto = require('crypto');

const RMQ_URL = process.env.RMQ_URL || 'amqp://bigboss:secure@100.100.36.22:5672/messenger';

(async () => {
    const conn = await amqp.connect(RMQ_URL);
    const ch = await conn.createChannel();
    await ch.assertQueue('q_auth_requests', { durable: true });
    ch.prefetch(1);

    const users = new Map();     // username -> { password, email }
    const sessions = new Map();  // token -> username

    ch.consume('q_auth_requests', (msg) => {
        const req = JSON.parse(msg.content.toString());
        let res;

        switch (req.action) {
            case 'register':
                if (users.has(req.username)) {
                    res = { success: false, error: 'Username already taken.' };
                } else {
                    users.set(req.username, { password: req.password, email: req.email });
                    res = { success: true, user: { username: req.username, email: req.email } };
                }
                break;
            case 'login': {
                const u = users.get(req.username);
                if (u && u.password === req.password) {
                    const token = crypto.randomUUID();
                    sessions.set(token, req.username);
                    res = { success: true, token, user: { username: req.username } };
                } else {
                    res = { success: false, error: 'Invalid username or password.' };
                }
                break;
            }
            case 'validate':
                res = sessions.has(req.token)
                    ? { success: true, user: { username: sessions.get(req.token) } }
                    : { success: false };
                break;
            case 'logout':
                sessions.delete(req.token);
                res = { success: true };
                break;
            default:
                res = { success: false, error: 'Unknown action.' };
        }

        ch.sendToQueue(msg.properties.replyTo, Buffer.from(JSON.stringify(res)), {
            correlationId: msg.properties.correlationId,
        });
        ch.ack(msg);
    });

    console.log('[stub] Listening on q_auth_requests');
})();