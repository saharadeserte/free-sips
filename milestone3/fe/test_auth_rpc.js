const amqp = require('amqplib');
const crypto = require('crypto');

const URL = 'amqp://bigboss:secure@100.100.36.22:5672/messenger';
const AUTH_QUEUE = 'q_auth_requests';

// Send one RPC request and wait for the reply with the matching correlationId
async function rpc(ch, payload, timeoutMs = 5000) {
  const { queue: replyQ } = await ch.assertQueue('', { exclusive: true });
  const correlationId = crypto.randomUUID();

  const reply = new Promise((resolve) => {
    ch.consume(replyQ, (msg) => {
      if (msg && msg.properties.correlationId === correlationId) {
        resolve(JSON.parse(msg.content.toString()));
      }
    }, { noAck: true });
  });

  ch.sendToQueue(AUTH_QUEUE, Buffer.from(JSON.stringify(payload)), {
    correlationId,
    replyTo: replyQ,
    contentType: 'application/json',
  });

  let timer;
  const timeout = new Promise((_, reject) => {
    timer = setTimeout(() => reject(new Error('no reply within timeout (is mock_befe.js running?)')), timeoutMs);
  });
  try {
    return await Promise.race([reply, timeout]);
  } finally {
    clearTimeout(timer);
  }
}

(async () => {
  const conn = await amqp.connect(URL);
  const ch = await conn.createChannel();
  const user = `probe_${Date.now()}`;
  const pw = 'longenough1';

  console.log('register:    ', await rpc(ch, { action: 'register', username: user, email: `${user}@test.local`, password: pw }));
  console.log('bad login:   ', await rpc(ch, { action: 'login', username: user, password: 'wrong-password' }));
  console.log('good login:  ', await rpc(ch, { action: 'login', username: user, password: pw }));

  await conn.close();
})().catch((e) => { console.error('[-]', e.message); process.exit(1); });
