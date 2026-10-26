using RabbitMQ.Client;
using RabbitMQ.Client.Events;
using System.Text;

public class DatabaseMessaging : BackgroundService
{
    private readonly RMQCredentials _cred;

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
            HostName = _cred.Host,
            Port = _cred.Port,
            UserName = _cred.Username,
            Password = _cred.Password,
            VirtualHost = _cred.VirtualHost
        };

        Console.WriteLine($"[*] Connecting to {_cred.Host}:{_cred.Port}/{_cred.VirtualHost} as {_cred.Username}");

        while (!token.IsCancellationRequested)
        {
            try
            {
                using var connection = await factory.CreateConnectionAsync();
                using var receiveChannel = await connection.CreateChannelAsync();
                using var sendChannel = await connection.CreateChannelAsync();

                await receiveChannel.ExchangeDeclareAsync(_cred.SendExchange, ExchangeType.Direct, durable: true);
                await receiveChannel.QueueDeclareAsync(_cred.SendQueue, durable: true, exclusive: false, autoDelete: false);
                await receiveChannel.QueueBindAsync(_cred.SendQueue, _cred.SendExchange, _cred.SendRoutingKey);

                if (!string.IsNullOrWhiteSpace(_cred.Exchange))
                {
                    await sendChannel.ExchangeDeclareAsync(_cred.Exchange, ExchangeType.Direct, durable: true);
                }

                Console.WriteLine("Connection Succeeded");

                var shutdown = new TaskCompletionSource();
                connection.ConnectionShutdownAsync += (s, e) =>
                {
                    Console.WriteLine($"[!] Connection shut down: {e.ReplyText}");
                    shutdown.TrySetResult();
                    return Task.CompletedTask;
                };

                var consumer = new AsyncEventingBasicConsumer(receiveChannel);
                consumer.ReceivedAsync += async (model, arg) =>
                {
                    var message = Encoding.UTF8.GetString(arg.Body.ToArray());
                    Console.WriteLine($"Received message: '{message}'");

                    try
                    {
                        await PublishMessage(sendChannel, message);
                        await receiveChannel.BasicAckAsync(arg.DeliveryTag, multiple: false);
                        Console.WriteLine($"Sent message: '{message}'");
                    }
                    catch (Exception ex)
                    {
                        Console.WriteLine($"[!] Publish failed, requeueing: {ex.Message}");
                        await receiveChannel.BasicNackAsync(arg.DeliveryTag, multiple: false, requeue: true);
                    }
                };

                await receiveChannel.BasicConsumeAsync(_cred.SendQueue, autoAck: false, consumer: consumer);

                // Stay here until the app stops or the broker drops the connection
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
        }
    }

    private async Task PublishMessage(IChannel channel, string message)
    {
        var body = Encoding.UTF8.GetBytes(message);
        await channel.BasicPublishAsync(_cred.Exchange, _cred.RoutingKey, body);
    }
}