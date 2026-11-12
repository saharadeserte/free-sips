// Stand-in for Backend-FE (Harry). Consumes q_auth_requests, hashes with bcrypt cost 12,
// and talks to be-db over bedb_exchange / bedb.rpc.
// Use when BE-FE VM is unavailable

const amqp = require('amqplib');
const bcrypt = require('bcryptjs');
const crypto = require('crypto');

const RMQ_URL = process.env.RMQ_URL || 'amqp://bigboss:secure@100.100.36.22:5672/messenger';
const AUTH_QUEUE = 'q_auth_requests';
const DB_EXCHANGE = 'bedb_exchange';
const DB_ROUTING_KEY = 'bedb.rpc';
const DB_TIMEOUT_MS = 3000;
const SESSION_SECONDS = 86400;
const BCRYPT_COST = 12;

const sha256 = (s) => crypto.createHash('sha256').update(s).digest('hex');

// Send one request to be-db and wait for the reply with the matching correlationId
async function dbRpc(conn, payload) {
    const ch = await conn.createChannel();
    try {
        const replyQ = await ch.assertQueue('', { exclusive: true, autoDelete: true });
        const correlationId = crypto.randomUUID();

        const reply = new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error('be-db timed out')), DB_TIMEOUT_MS);
            ch.consume(replyQ.queue, (msg) => {
                if (msg && msg.properties.correlationId === correlationId) {
                    clearTimeout(timer);
                    resolve(JSON.parse(msg.content.toString()));
                }
            }, { noAck: true }).catch(reject);
        });

        ch.publish(DB_EXCHANGE, DB_ROUTING_KEY, Buffer.from(JSON.stringify(payload)), {
            correlationId,
            replyTo: replyQ.queue,
            contentType: 'application/json',
            persistent: true,
        });
        return await reply;
    } finally {
        ch.close().catch(() => { });
    }
}

async function handle(conn, req) {
    switch (req.action) {
        case 'register': {
            const username = String(req.username || '').trim();
            const email = String(req.email || '').trim().toLowerCase();
            const password = String(req.password || '');
            if (!username || !email || password.length < 8) {
                return { success: false, error: 'Invalid registration details.' };
            }
            const password_hash = await bcrypt.hash(password, BCRYPT_COST);
            return dbRpc(conn, { action: 'register', username, email, password_hash });
        }

        case 'login': {
            const found = await dbRpc(conn, { action: 'login', username: String(req.username || '') });
            console.log(`[bridge] raw lookup reply: ${JSON.stringify(found)}`);
            console.log(`[bridge] lookup ${req.username}: success=${found.success}, hasHash=${!!found.password_hash}, hashPrefix=${(found.password_hash || '').slice(0, 7)}`);
            if (!found.success) return { success: false, error: 'Invalid username or password.' };

            const valid = !!found.password_hash &&
                await bcrypt.compare(String(req.password || ''), found.password_hash);
            console.log(`[bridge] bcrypt match=${valid}`);

            await dbRpc(conn, { action: 'login_result', user_id: found.user_id, success: valid });
            if (!valid) return { success: false, error: 'Invalid username or password.' };

            const token = crypto.randomBytes(32).toString('hex');
            const created = await dbRpc(conn, {
                action: 'create_session',
                user_id: found.user_id,
                token_hash: sha256(token),
                ttl_seconds: SESSION_SECONDS,
            });
            if (!created.success) return { success: false, error: 'Could not start a session. Please try again.' };

            return { success: true, token, user: { username: found.username } };
        }

        case 'validate': {
            const r = await dbRpc(conn, { action: 'validate_session', token_hash: sha256(String(req.token || '')) });
            return r.success ? { success: true, user: { username: r.username } } : { success: false };
        }

        case 'logout': {
            await dbRpc(conn, { action: 'delete_session', token_hash: sha256(String(req.token || '')) })
                .catch(() => { });
            return { success: true };
        }

        default:
            return { success: false, error: 'invalid_action' };
    }
}

(async () => {
    const conn = await amqp.connect(RMQ_URL);
    conn.on('error', (err) => { console.error('[bridge] connection error:', err.message); process.exit(1); });
    conn.on('close', () => { console.error('[bridge] connection closed'); process.exit(1); });

    const ch = await conn.createChannel();
    await ch.assertQueue(AUTH_QUEUE, { durable: true });
    ch.prefetch(1);
    console.log(`[bridge] Listening on ${AUTH_QUEUE}, forwarding to ${DB_EXCHANGE}/${DB_ROUTING_KEY}`);

    ch.consume(AUTH_QUEUE, async (msg) => {
        if (!msg) return;
        let req = {};
        let reply;
        try {
            req = JSON.parse(msg.content.toString());
            reply = await handle(conn, req);
            console.log(`[bridge] ${req.action} handled, success=${reply.success}`);
        } catch (err) {
            console.error(`[bridge] ${req.action || 'request'} failed: ${err.message}`);
            reply = { success: false, error: 'Service temporarily unavailable. Please try again.' };
        }

        if (msg.properties.replyTo) {
            ch.sendToQueue(msg.properties.replyTo, Buffer.from(JSON.stringify(reply)), {
                correlationId: msg.properties.correlationId,
                contentType: 'application/json',
            });
        }
        ch.ack(msg);
    });
})().catch((err) => {
    console.error('[bridge] fatal:', err.message);
    process.exit(1);
});