const express = require('express');
const amqp = require('amqplib');
const path = require('path');

const app = express();
const PORT = 7812;

// Central RabbitMQ Broker Details
const RMQ_HOST = '100.100.36.22';
const RMQ_PORT = 5672;
const RMQ_USER = 'bigboss';
const RMQ_PASS = 'secure';
const RMQ_VHOST = 'messenger';
const LOG_QUEUE = 'q_central_logging';

app.use(express.json());
app.use(express.static(path.join(__dirname, 'public')));

// Prevent unhandled broker drops from crashing the Node process
process.on('unhandledRejection', (reason, promise) => {
    console.error('[!] Handled Async Rejection:', reason);
});

process.on('uncaughtException', (err) => {
    console.error('[!] Handled Exception:', err.message);
});

// Centralized logging helper function
async function sendCentralizedLog(level, message) {
    const encodedVhost = encodeURIComponent(RMQ_VHOST);
    const amqpUrl = `amqp://${RMQ_USER}:${RMQ_PASS}@${RMQ_HOST}:${RMQ_PORT}/${encodedVhost}`;
    
    try {
        const conn = await amqp.connect(amqpUrl);
        const ch = await conn.createChannel();
        await ch.assertQueue(LOG_QUEUE, { durable: true });

        const logPayload = {
            service: 'frontend',
            level: level || 'INFO',
            message: message || 'Hello World!!!',
            timestamp: new Date().toISOString()
        };

        ch.sendToQueue(LOG_QUEUE, Buffer.from(JSON.stringify(logPayload)), { persistent: true });
        console.log(`[x] Centralized Log Dispatched:`, logPayload);

        setTimeout(() => {
            conn.close().catch(() => {});
        }, 500);

        return { success: true, payload: logPayload };
    } catch (err) {
        console.error(`[!] Failed to publish log:`, err.message);
        return { success: false, error: err.message };
    }
}

// Verification route to send log to queue
app.post('/api/log', async (req, res) => {
    const { level, message } = req.body;
    const result = await sendCentralizedLog(level, message);
    res.json(result);
});

// Minimum requirement check endpoint
app.get('/raw', (req, res) => {
    res.status(200).send("Hello World!!!");
});

app.listen(PORT, '0.0.0.0', () => {
    console.log(`[*] FrontEnd service running on http://0.0.0.0:${PORT}`);
    sendCentralizedLog('INFO', `FrontEnd service booted successfully on port ${PORT}`);
});
