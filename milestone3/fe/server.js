const express = require('express');
const amqp = require('amqplib');
const path = require('path');
const crypto = require('crypto');

const app = express();
const PORT = 7812;

const RMQ_URL = process.env.RMQ_URL ||
    `amqp://bigboss:secure@100.100.36.22:5672/${encodeURIComponent('messenger')}`;
const LOG_QUEUE = 'q_central_logging';
const AUTH_QUEUE = 'q_auth_requests';
const RPC_TIMEOUT_MS = 5000;
const COOKIE_NAME = 'fs_session';

app.use(express.json());
// index: false so "/" is handled by the session-aware route below
app.use(express.static(path.join(__dirname, 'public'), { index: false }));

process.on('unhandledRejection', (reason) => console.error('[!] Async rejection:', reason));
process.on('uncaughtException', (err) => console.error('[!] Exception:', err.message));

// ---------- RabbitMQ connection (one shared connection, reconnects on failure) ----------
let connPromise = null;
function getConnection() {
    if (!connPromise) {
        connPromise = amqp.connect(RMQ_URL)
            .then((conn) => {
                conn.on('error', (err) => { console.error('[!] RMQ error:', err.message); connPromise = null; });
                conn.on('close', () => { connPromise = null; });
                return conn;
            })
            .catch((err) => { connPromise = null; throw err; });
    }
    return connPromise;
}

// ---------- Request/reply over RabbitMQ ----------
async function rpc(payload) {
    const conn = await getConnection();
    const ch = await conn.createChannel();
    try {
        await ch.assertQueue(AUTH_QUEUE, { durable: true });
        const replyQ = await ch.assertQueue('', { exclusive: true, autoDelete: true });
        const correlationId = crypto.randomUUID();

        const reply = new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error('Auth service timed out')), RPC_TIMEOUT_MS);
            ch.consume(replyQ.queue, (msg) => {
                if (msg && msg.properties.correlationId === correlationId) {
                    clearTimeout(timer);
                    resolve(JSON.parse(msg.content.toString()));
                }
            }, { noAck: true }).catch(reject);
        });

        ch.sendToQueue(AUTH_QUEUE, Buffer.from(JSON.stringify(payload)), {
            persistent: true,
            correlationId,
            replyTo: replyQ.queue,
        });
        return await reply;
    } finally {
        setTimeout(() => ch.close().catch(() => {}), 500);
    }
}

// ---------- Centralized logging (fire-and-forget) ----------
async function sendCentralizedLog(level, message) {
    try {
        const conn = await getConnection();
        const ch = await conn.createChannel();
        await ch.assertQueue(LOG_QUEUE, { durable: true });
        const payload = { service: 'frontend', level, message, timestamp: new Date().toISOString() };
        ch.sendToQueue(LOG_QUEUE, Buffer.from(JSON.stringify(payload)), { persistent: true });
        setTimeout(() => ch.close().catch(() => {}), 500);
        return { success: true, payload };
    } catch (err) {
        console.error('[!] Failed to publish log:', err.message);
        return { success: false, error: err.message };
    }
}

// ---------- Session helpers ----------
function parseCookies(header = '') {
    return Object.fromEntries(
        header.split(';').map((c) => c.trim()).filter(Boolean).map((c) => {
            const i = c.indexOf('=');
            return [decodeURIComponent(c.slice(0, i)), decodeURIComponent(c.slice(i + 1))];
        })
    );
}

async function getSessionUser(req) {
    const token = parseCookies(req.headers.cookie)[COOKIE_NAME];
    if (!token) return null;
    try {
        const r = await rpc({ action: 'validate', token });
        return r.success ? r.user : null;
    } catch {
        return null;
    }
}

// ---------- Page routes ----------
app.get('/', async (req, res) => {
    const user = await getSessionUser(req);
    res.redirect(user ? '/landing' : '/login');
});

app.get('/landing', async (req, res) => {
    if (!(await getSessionUser(req))) return res.redirect('/login');
    res.sendFile(path.join(__dirname, 'public', 'landing.html'));
});

app.get('/login', async (req, res) => {
    if (await getSessionUser(req)) return res.redirect('/landing');
    res.sendFile(path.join(__dirname, 'public', 'login.html'));
});

app.get('/register', async (req, res) => {
    if (await getSessionUser(req)) return res.redirect('/landing');
    res.sendFile(path.join(__dirname, 'public', 'register.html'));
});

// ---------- Auth API ----------
app.post('/api/register', async (req, res) => {
    const username = String(req.body?.username || '').trim();
    const email = String(req.body?.email || '').trim().toLowerCase();
    const password = String(req.body?.password || '');

    if (!username || !email || !password) {
        return res.status(400).json({ success: false, error: 'All fields are required.' });
    }
    if (password.length < 8) {
        return res.status(400).json({ success: false, error: 'Password must be at least 8 characters.' });
    }

    try {
        const r = await rpc({ action: 'register', username, email, password });
        sendCentralizedLog(r.success ? 'INFO' : 'WARNING',
            `Registration ${r.success ? 'succeeded' : 'failed'} for user "${username}"`);
        res.status(r.success ? 201 : 400).json(r);
    } catch (err) {
        res.status(503).json({ success: false, error: err.message });
    }
});

app.post('/api/login', async (req, res) => {
    const username = String(req.body?.username || '').trim();
    const password = String(req.body?.password || '');

    if (!username || !password) {
        return res.status(400).json({ success: false, error: 'Username and password are required.' });
    }

    try {
        const r = await rpc({ action: 'login', username, password });
        sendCentralizedLog(r.success ? 'INFO' : 'WARNING',
            `Login ${r.success ? 'succeeded' : 'failed'} for user "${username}"`);

        if (!r.success) return res.status(401).json({ success: false, error: r.error || 'Invalid username or password.' });

        res.setHeader('Set-Cookie',
            `${COOKIE_NAME}=${encodeURIComponent(r.token)}; HttpOnly; SameSite=Lax; Path=/; Max-Age=86400`);
        res.json({ success: true, user: r.user });
    } catch (err) {
        res.status(503).json({ success: false, error: err.message });
    }
});

app.get('/api/me', async (req, res) => {
    const user = await getSessionUser(req);
    if (!user) return res.status(401).json({ success: false, error: 'Not logged in.' });
    res.json({ success: true, user });
});

app.post('/api/logout', async (req, res) => {
    const token = parseCookies(req.headers.cookie)[COOKIE_NAME];
    if (token) {
        try { await rpc({ action: 'logout', token }); } catch { /* clear cookie regardless */ }
    }
    res.setHeader('Set-Cookie', `${COOKIE_NAME}=; HttpOnly; SameSite=Lax; Path=/; Max-Age=0`);
    res.json({ success: true });
});

// ---------- Logging check (kept for the messaging-container deliverable) ----------
app.post('/api/log', async (req, res) => {
    const { level, message } = req.body || {};
    res.json(await sendCentralizedLog(level || 'INFO', message || 'Hello World!!!'));
});

app.get('/raw', (req, res) => res.status(200).send('Hello World!!!'));

app.listen(PORT, '0.0.0.0', () => {
    console.log(`[*] FrontEnd service running on http://0.0.0.0:${PORT}`);
    sendCentralizedLog('INFO', `FrontEnd service booted successfully on port ${PORT}`);
});