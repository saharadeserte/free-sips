using RabbitMQ.Client;
using RabbitMQ.Client.Events;
using System.Collections.Concurrent;
using System.Text;

public class DatabaseMessaging : BackgroundService
{
    private readonly RMQCredentials _cred;
 
    private sealed record Pending(string ReplyTo, string CorrelationId, ulong DeliveryTag, DateTime CreatedUtc);
 
    public DatabaseMessaging(IConfiguration config)
    {
        _cred = new RMQCredentials();
        config.GetSection("Database").Bind(_cred);
    }
 
    protected override async Task ExecuteAsync(CancellationToken token)
    {
        if (string.IsNullOrWhiteSpace(_cred.SendQueue) || string.IsNullOrWhiteSpace(_cred.SendExchange))
        {
            throw new InvalidOperationException(
                "Database section in appsettings.json is missing SendQueue or SendExchange.");
        }
 
        var factory = new ConnectionFactory
        {
            UserName = _cred.Username,
            Password = _cred.Password,
            VirtualHost = _cred.VirtualHost,
            RequestedHeartbeat = TimeSpan.FromSeconds(30),

            AutomaticRecoveryEnabled = false
        };
 
        var hosts = _cred.GetHosts();
        Console.WriteLine($"[*] RabbitMQ nodes: {string.Join(", ", hosts)} (vhost {_cred.VirtualHost}, user {_cred.Username})");
 
        var queueArgs = new Dictionary<string, object?>();
        if (_cred.QueueType.Equals("quorum", StringComparison.OrdinalIgnoreCase))
        {
            queueArgs["x-queue-type"] = "quorum";
        }
 
        while (!token.IsCancellationRequested)
        {
            using var connCts = CancellationTokenSource.CreateLinkedTokenSource(token);
            try
            {

                var endpoints = hosts
                    .OrderBy(_ => Random.Shared.Next())
                    .Select(h => new AmqpTcpEndpoint(h, _cred.Port))
                    .ToList();
 
                using var connection = await factory.CreateConnectionAsync(
                    endpoints, $"be-db-{Environment.MachineName}", token);
                using var receiveChannel = await connection.CreateChannelAsync(); // requests in
                using var sendChannel = await connection.CreateChannelAsync();    // DB hop + relayed replies

                await receiveChannel.ExchangeDeclareAsync(_cred.SendExchange, ExchangeType.Direct, durable: true);
                await receiveChannel.QueueDeclareAsync(
                    _cred.SendQueue, durable: true, exclusive: false, autoDelete: false, arguments: queueArgs);
                await receiveChannel.QueueBindAsync(_cred.SendQueue, _cred.SendExchange, _cred.SendRoutingKey);
                await receiveChannel.BasicQosAsync(0, (ushort)_cred.PrefetchCount, false);

                var myReplyQueue = (await sendChannel.QueueDeclareAsync(
                    "", durable: false, exclusive: true, autoDelete: true)).QueueName;
 
                sendChannel.BasicReturnAsync += (s, e) =>
                {
                    Console.WriteLine($"[!] Unroutable message (no queue '{e.RoutingKey}' on '{e.Exchange}')");
                    return Task.CompletedTask;
                };
 
                var pending = new ConcurrentDictionary<string, Pending>();
 
                var shutdown = new TaskCompletionSource();
                connection.ConnectionShutdownAsync += (s, e) =>
                {
                    Console.WriteLine($"[!] Connection shut down: {e.ReplyText}");
                    shutdown.TrySetResult();
                    return Task.CompletedTask;
                };
 
                var replyConsumer = new AsyncEventingBasicConsumer(sendChannel);
                replyConsumer.ReceivedAsync += async (model, ea) =>
                {
                    var requestId = ea.BasicProperties.CorrelationId ?? "";
                    if (!pending.TryRemove(requestId, out var p))
                    {
                        Console.WriteLine($"[!] Reply for unknown/expired request {requestId}, dropping");
                        return;
                    }
 
                    Console.WriteLine($"Database replied for {p.CorrelationId}: '{Encoding.UTF8.GetString(ea.Body.ToArray())}'");
                    await RelayAsync(sendChannel, receiveChannel, p, ea.Body);
                };
                await sendChannel.BasicConsumeAsync(myReplyQueue, autoAck: true, consumer: replyConsumer);
 
                var requestConsumer = new AsyncEventingBasicConsumer(receiveChannel);
                requestConsumer.ReceivedAsync += async (model, arg) =>
                {
                    var replyTo = arg.BasicProperties.ReplyTo;
                    var corrId = arg.BasicProperties.CorrelationId ?? "";
                    Console.WriteLine($"Received request: '{Encoding.UTF8.GetString(arg.Body.ToArray())}'");
 
                    if (string.IsNullOrEmpty(replyTo))
                    {
                        Console.WriteLine("[!] Request has no reply_to, rejecting");
                        await receiveChannel.BasicNackAsync(arg.DeliveryTag, false, requeue: false);
                        return;
                    }
 
                    var requestId = Guid.NewGuid().ToString("N");
                    pending[requestId] = new Pending(replyTo, corrId, arg.DeliveryTag, DateTime.UtcNow);
 
                    try
                    {
                        var props = new BasicProperties
                        {
                            ReplyTo = myReplyQueue,
                            CorrelationId = requestId,
                            ContentType = arg.BasicProperties.ContentType ?? "application/json"
                        };
                        await sendChannel.BasicPublishAsync(
                            _cred.Exchange ?? "", _cred.RoutingKey, true, props, arg.Body);
                    }
                    catch (Exception ex)
                    {
                        Console.WriteLine($"[!] Forward to database failed, requeueing: {ex.Message}");
                        pending.TryRemove(requestId, out _);
                        try { await receiveChannel.BasicNackAsync(arg.DeliveryTag, false, requeue: true); }
                        catch { }
                    }
                };
                await receiveChannel.BasicConsumeAsync(_cred.SendQueue, autoAck: false, consumer: requestConsumer);
 
                _ = Task.Run(async () =>
                {
                    try
                    {
                        while (!connCts.IsCancellationRequested)
                        {
                            await Task.Delay(1000, connCts.Token);
                            var cutoff = DateTime.UtcNow.AddSeconds(-_cred.ReplyTimeoutSeconds);
                            foreach (var kv in pending.Where(kv => kv.Value.CreatedUtc < cutoff).ToList())
                            {
                                if (!pending.TryRemove(kv.Key, out var p)) continue;
                                Console.WriteLine($"[!] Database timeout for {p.CorrelationId}");
                                var err = Encoding.UTF8.GetBytes("{\"success\":false,\"error\":\"database_timeout\"}");
                                await RelayAsync(sendChannel, receiveChannel, p, err);
                            }
                        }
                    }
                    catch (OperationCanceledException) { }
                    catch (Exception ex) { Console.WriteLine($"[!] Sweeper stopped: {ex.Message}"); }
                });
 
                Console.WriteLine($"Connection Succeeded (node {connection.Endpoint.HostName}), reply queue {myReplyQueue}");
 
                await Task.WhenAny(shutdown.Task, Task.Delay(Timeout.Infinite, token));
            }
            catch (OperationCanceledException) when (token.IsCancellationRequested)
            {
                break;
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[!] RabbitMQ error ({ex.GetType().Name}): {ex.Message}");
                await Task.Delay(TimeSpan.FromSeconds(5), token);
            }
            finally
            {
                connCts.Cancel();
            }
        }
    }
 

    private static async Task RelayAsync(IChannel sendChannel, IChannel receiveChannel, Pending p, ReadOnlyMemory<byte> body)
    {
        try
        {
            var props = new BasicProperties
            {
                CorrelationId = p.CorrelationId,
                ContentType = "application/json"
            };
            await sendChannel.BasicPublishAsync("", p.ReplyTo, true, props, body);
            await receiveChannel.BasicAckAsync(p.DeliveryTag, false);
            Console.WriteLine($"Relayed answer to {p.ReplyTo} ({p.CorrelationId})");
        }
        catch (Exception ex)
        {
            Console.WriteLine($"[!] Relay failed for {p.CorrelationId}: {ex.Message}");
            try { await receiveChannel.BasicNackAsync(p.DeliveryTag, false, requeue: true); }
            catch { }
        }
    }
}