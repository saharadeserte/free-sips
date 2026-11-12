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
const RPC_TIMEOUT_MS = 8000;
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
        setTimeout(() => ch.close().catch(() => { }), 500);
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
        setTimeout(() => ch.close().catch(() => { }), 500);
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

// Returns { state: 'ok' | 'anonymous' | 'unavailable', user? }
async function checkSession(req) {
    const token = parseCookies(req.headers.cookie)[COOKIE_NAME];
    if (!token) return { state: 'anonymous' };
    try {
        const r = await rpc({ action: 'validate', token });
        if (r.success && r.user) return { state: 'ok', user: r.user };
        // "No session" replies have no error field. A reply with an error means the backend failed.
        if (r.error) {
            console.error(`[!] Session check returned error: "${r.error}". Not redirecting.`);
            return { state: 'unavailable' };
        }
        return { state: 'anonymous' };
    } catch (err) {
        console.error('[!] Session check failed:', err.message);
        return { state: 'unavailable' };
    }
}

function setSessionCookie(res, token) {
    res.setHeader('Set-Cookie',
        `${COOKIE_NAME}=${encodeURIComponent(token)}; HttpOnly; SameSite=Lax; Path=/`);
}

// ---------- Page routes ----------
app.get('/', (req, res) => {
    res.sendFile(path.join(__dirname, 'public', 'landing.html'));
});

app.get('/landing', (req, res) => {
    res.sendFile(path.join(__dirname, 'public', 'landing.html'));
});

app.get('/login', async (req, res) => {
    const s = await checkSession(req);
    if (s.state === 'ok') return res.redirect('/landing');
    res.sendFile(path.join(__dirname, 'public', 'login.html'));
});

app.get('/register', async (req, res) => {
    const s = await checkSession(req);
    if (s.state === 'ok') return res.redirect('/landing');
    res.sendFile(path.join(__dirname, 'public', 'register.html'));
});

// ---------- Auth API ----------
app.get('/api/me', async (req, res) => {
    const s = await checkSession(req);
    if (s.state === 'ok') return res.json({ success: true, user: s.user });
    if (s.state === 'unavailable') {
        return res.status(503).json({ success: false, error: 'Session check unavailable.' });
    }
    res.status(401).json({ success: false, error: 'Not logged in.' });
});

app.post('/api/register', async (req, res) => {
    const { username, password, email } = req.body || {};
    if (!username || !password) {
        return res.status(400).json({ success: false, error: 'Username and password are required.' });
    }
    try {
        const r = await rpc({ action: 'register', username, password, email: email || '' });
        if (!r.success) return res.status(409).json({ success: false, error: r.error || 'Registration failed.' });
        res.json({ success: true, user: r.user });
    } catch (err) {
        console.error('[!] Register failed:', err.message);
        res.status(503).json({ success: false, error: 'Login service is not responding. Please try again.' });
    }
});

app.post('/api/login', async (req, res) => {
    const { username, password } = req.body || {};
    if (!username || !password) {
        return res.status(400).json({ success: false, error: 'Username and password are required.' });
    }
    try {
        const r = await rpc({ action: 'login', username, password });
        if (!r.success || !r.token) {
            return res.status(401).json({ success: false, error: r.error || 'Invalid username or password.' });
        }
        setSessionCookie(res, r.token);
        res.json({ success: true, user: r.user });
    } catch (err) {
        console.error('[!] Login failed:', err.message);
        res.status(503).json({ success: false, error: 'Login service is not responding. Please try again.' });
    }
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
