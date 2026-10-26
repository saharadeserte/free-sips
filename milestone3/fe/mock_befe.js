// Mock BE-FE: consumes auth RPC requests from RabbitMQ and replies on the caller's callback queue.
// Users live in memory only (lost on restart). For testing the FrontEnd until the real BE-FE is ready.
//
// Run:  node mock_befe.js
// Env:  RMQ_HOST RMQ_PORT RMQ_USER RMQ_PASS RMQ_VHOST AUTH_QUEUE (same defaults as server.js)
//       MOCK_DELAY_MS=0   artificial latency before replying
const amqp = require('amqplib');
const crypto = require('crypto');

const RMQ_HOST = process.env.RMQ_HOST || '100.100.36.22';
const RMQ_PORT = process.env.RMQ_PORT || 5672;
const RMQ_USER = process.env.RMQ_USER || 'bigboss';
const RMQ_PASS = process.env.RMQ_PASS || 'secure';
const RMQ_VHOST = process.env.RMQ_VHOST || 'messenger';
const AUTH_QUEUE = process.env.AUTH_QUEUE || 'q_auth_requests';
const DELAY_MS = Number(process.env.MOCK_DELAY_MS || 0);

const users = new Map(); // username(lowercase) -> { username, email, salt, hash }

function hashPassword(password, salt) {
    return crypto.scryptSync(password, salt, 32).toString('hex');
}

// Pure request -> reply function (easy to test without RabbitMQ)
function handle(req) {
    const action = req && req.action;
    const username = String((req && req.username) || '').trim();
    const password = String((req && req.password) || '');

    if (action === 'register') {
        const email = String(req.email || '').trim().toLowerCase();
        if (!username || !email || password.length < 8) {
            return { success: false, message: 'Invalid registration data.' };
        }
        if (users.has(username.toLowerCase())) {
            return { success: false, message: 'That username is already taken.' };
        }
        for (const u of users.values()) {
            if (u.email === email) return { success: false, message: 'That email is already registered.' };
        }
        const salt = crypto.randomBytes(16).toString('hex');
        users.set(username.toLowerCase(), { username, email, salt, hash: hashPassword(password, salt) });
        return { success: true, message: 'Account created.', username };
    }

    if (action === 'login') {
        const key = username.toLowerCase();
        let user = users.get(key);
        if (!user) user = [...users.values()].find((u) => u.email === key); // allow email login
        if (!user) return { success: false, message: 'Invalid username or password.' };

        const attempt = Buffer.from(hashPassword(password, user.salt), 'hex');
        const actual = Buffer.from(user.hash, 'hex');
        if (!crypto.timingSafeEqual(attempt, actual)) {
            return { success: false, message: 'Invalid username or password.' };
        }
        return {
            success: true,
            message: 'Login successful.',
            username: user.username,
            token: crypto.randomBytes(32).toString('hex'),
        };
    }

    return { success: false, message: `Unknown action: ${action}` };
}

async function main() {
    const url = `amqp://${encodeURIComponent(RMQ_USER)}:${encodeURIComponent(RMQ_PASS)}@${RMQ_HOST}:${RMQ_PORT}/${encodeURIComponent(RMQ_VHOST)}`;
    const conn = await amqp.connect(url);
    conn.on('close', () => { console.error('[!] RMQ connection closed, exiting'); process.exit(1); });
    conn.on('error', (e) => console.error('[!] RMQ error:', e.message));

    const ch = await conn.createChannel();
    await ch.assertQueue(AUTH_QUEUE, { durable: true }); // must match server.js
    ch.prefetch(1);

    console.log(`[*] Mock BE-FE listening on '${AUTH_QUEUE}' (vhost ${RMQ_VHOST}). Ctrl+C to stop.`);

    await ch.consume(AUTH_QUEUE, async (msg) => {
        if (!msg) return;
        const { replyTo, correlationId } = msg.properties;
        let reply;
        try {
            const req = JSON.parse(msg.content.toString());
            reply = handle(req);
            console.log(`[x] ${req.action} user="${req.username}" -> ${reply.success ? 'OK' : 'REJECTED'} (${reply.message})`);
        } catch (e) {
            console.error('[!] Bad request payload:', e.message);
            reply = { success: false, message: 'Malformed request.' };
        }

        if (DELAY_MS) await new Promise((r) => setTimeout(r, DELAY_MS));

        if (replyTo) {
            ch.sendToQueue(replyTo, Buffer.from(JSON.stringify(reply)), {
                correlationId,
                contentType: 'application/json',
            });
        } else {
            console.warn('[!] Request had no replyTo; dropping reply.');
        }
        ch.ack(msg);
    });
}

if (require.main === module) {
    main().catch((e) => { console.error('[-] Failed to start:', e.message); process.exit(1); });
}

module.exports = { handle };