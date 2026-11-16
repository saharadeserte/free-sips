using System.Collections.Concurrent;
using System.Text;
using System.Text.Json;
using RabbitMQ.Client;
using RabbitMQ.Client.Events;

// One long-lived connection + one private reply queue for many sequential RPCs.
// Publishes to bedb_exchange / bedb.rpc (the same entry point befe uses). The DatabaseMessaging
// relay forwards to databaseQueue and relays the reply back to our reply queue.
//
// Meant for ONE caller at a time (the ingestion worker). It is not a general-purpose pool.
public sealed class DbRpcClient : IAsyncDisposable
{
    private readonly RMQCredentials _cred;
    private readonly IngestionOptions _opt;
    private readonly SemaphoreSlim _connectLock = new(1, 1);
    private readonly ConcurrentDictionary<string, TaskCompletionSource<string>> _pending = new();

    private IConnection? _connection;
    private IChannel? _channel;
    private string _replyQueue = "";

    public DbRpcClient(IConfiguration config, IngestionOptions opt)
    {
        _opt = opt;
        _cred = new RMQCredentials();
        config.GetSection("Database").Bind(_cred);
    }

    public async Task<JsonDocument> CallAsync(object request, CancellationToken ct)
    {
        await EnsureConnectedAsync(ct);

        var correlationId = Guid.NewGuid().ToString("N");
        var tcs = new TaskCompletionSource<string>(TaskCreationOptions.RunContinuationsAsynchronously);
        _pending[correlationId] = tcs;

        try
        {
            var props = new BasicProperties
            {
                CorrelationId = correlationId,
                ReplyTo = _replyQueue,
                ContentType = "application/json",
                DeliveryMode = DeliveryModes.Persistent
            };
            var body = Encoding.UTF8.GetBytes(JsonSerializer.Serialize(request));
            await _channel!.BasicPublishAsync(_cred.SendExchange, _cred.SendRoutingKey, false, props, body, ct);

            var reply = await tcs.Task.WaitAsync(TimeSpan.FromSeconds(_opt.RpcTimeoutSeconds), ct);
            return JsonDocument.Parse(reply);
        }
        catch (TimeoutException)
        {
            throw new TimeoutException("No reply from the database worker.");
        }
        finally
        {
            _pending.TryRemove(correlationId, out _);
        }
    }

    private async Task EnsureConnectedAsync(CancellationToken ct)
    {
        if (_connection is { IsOpen: true } && _channel is { IsOpen: true }) return;

        await _connectLock.WaitAsync(ct);
        try
        {
            if (_connection is { IsOpen: true } && _channel is { IsOpen: true }) return;
            await CloseAsync();

            var factory = new ConnectionFactory
            {
                UserName = _cred.Username,
                Password = _cred.Password,
                VirtualHost = _cred.VirtualHost,
                RequestedHeartbeat = TimeSpan.FromSeconds(30),
                AutomaticRecoveryEnabled = false   // we reconnect ourselves on the next call
            };

            // Same node list the relay uses (Database:Hosts), in random order.
            var endpoints = _cred.GetHosts()
                .OrderBy(_ => Random.Shared.Next())
                .Select(h => new AmqpTcpEndpoint(h, _cred.Port))
                .ToList();

            _connection = await factory.CreateConnectionAsync(endpoints, $"be-db-ingest-{Environment.MachineName}", ct);
            _channel = await _connection.CreateChannelAsync();

            await _channel.ExchangeDeclareAsync(_cred.SendExchange, ExchangeType.Direct, durable: true);

            _replyQueue = (await _channel.QueueDeclareAsync("", durable: false, exclusive: true, autoDelete: true)).QueueName;

            var consumer = new AsyncEventingBasicConsumer(_channel);
            consumer.ReceivedAsync += (_, ea) =>
            {
                var id = ea.BasicProperties.CorrelationId ?? "";
                if (_pending.TryRemove(id, out var waiter))
                    waiter.TrySetResult(Encoding.UTF8.GetString(ea.Body.ToArray()));
                return Task.CompletedTask;
            };
            await _channel.BasicConsumeAsync(_replyQueue, autoAck: true, consumer: consumer);

            Console.WriteLine($"[ingest] RPC connected to {_connection.Endpoint.HostName}");
        }
        finally
        {
            _connectLock.Release();
        }
    }

    private async Task CloseAsync()
    {
        try { if (_channel is not null) await _channel.CloseAsync(); } catch { }
        try { if (_connection is not null) await _connection.CloseAsync(); } catch { }
        _channel = null;
        _connection = null;
    }

    public async ValueTask DisposeAsync() => await CloseAsync();
}
