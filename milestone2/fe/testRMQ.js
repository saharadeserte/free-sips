// test_rmq_conn.js
const amqp = require('amqplib');

const RMQ_HOST = '100.100.36.22';
const RMQ_PORT = 5672;
const RMQ_USER = 'bigboss';
const RMQ_PASS = 'secure';
const RMQ_VHOST = 'messenger';
const QUEUE_NAME = 'q_central_logging';

async function testConnection() {
    // URL-encode vhost to handle special characters if necessary
    const connectionUrl = `amqp://${RMQ_USER}:${RMQ_PASS}@${RMQ_HOST}:${RMQ_PORT}/${encodeURIComponent(RMQ_VHOST)}`;

    console.log(`[*] Connecting to RabbitMQ at ${RMQ_HOST}:${RMQ_PORT}/${RMQ_VHOST}...`);
    try {
        const connection = await amqp.connect(connectionUrl);
        console.log('[+] Connected successfully!');

        const channel = await connection.createChannel();
        console.log('[+] AMQP channel opened.');

        // Assert durable queue for centralized logging
        await channel.assertQueue(QUEUE_NAME, { durable: true });
        console.log(`[+] Queue '${QUEUE_NAME}' verified/created.`);

        // Test payload
        const testPayload = {
            service: 'frontend',
            level: 'INFO',
            message: 'Milestone 2 FrontEnd RabbitMQ handshake verification',
            timestamp: new Date().toISOString()
        };

        // Publish test message
        channel.sendToQueue(QUEUE_NAME, Buffer.from(JSON.stringify(testPayload)), { persistent: true });
        console.log('[+] Test log published:', testPayload);

        // Close after publishing
        setTimeout(async () => {
            await channel.close();
            await connection.close();
            console.log('[*] Test connection closed cleanly.');
            process.exit(0);
        }, 500);

    } catch (err) {
        console.error('[-] RabbitMQ Connection Failed:', err.message);
        process.exit(1);
    }
}

testConnection();
