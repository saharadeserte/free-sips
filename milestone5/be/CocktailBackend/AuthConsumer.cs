using RabbitMQ.Client;
using RabbitMQ.Client.Events;
using System.Text;

public class AuthConsumer : BackgroundService
{
    private const string QueueName = "q_auth_requests";
    private readonly AuthService _authService;

    public AuthConsumer(AuthService authService)
    {
        _authService = authService;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        var factory = RabbitConfig.CreateFactory();

        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                using var connection = await factory.CreateConnectionAsync(
                    RabbitConfig.GetEndpoints(), $"be-fe-{Environment.MachineName}", stoppingToken);
                using var channel = await connection.CreateChannelAsync();
                using var replyChannel = await connection.CreateChannelAsync();

                await channel.BasicQosAsync(0, 10, false);

                var queueArgs = new Dictionary<string, object?> { ["x-queue-type"] = "quorum" };
                await channel.QueueDeclareAsync(
                    QueueName, durable: true, exclusive: false, autoDelete: false, arguments: queueArgs);

                var shutdown = new TaskCompletionSource();
                connection.ConnectionShutdownAsync += (_, _) => { shutdown.TrySetResult(); return Task.CompletedTask; };

                var consumer = new AsyncEventingBasicConsumer(channel);
                consumer.ReceivedAsync += async (_, ea) =>
                {
                    using var cts = new CancellationTokenSource(TimeSpan.FromSeconds(6));
                    var message = Encoding.UTF8.GetString(ea.Body.ToArray());
                    var response = await _authService.ProcessRequestAsync(message, cts.Token);

                    if (!string.IsNullOrEmpty(ea.BasicProperties.ReplyTo))
                    {
                        var replyProps = new BasicProperties
                        {
                            CorrelationId = ea.BasicProperties.CorrelationId,
                            ContentType = "application/json"
                        };
                        await replyChannel.BasicPublishAsync(
                            "", ea.BasicProperties.ReplyTo, true, replyProps, Encoding.UTF8.GetBytes(response));
                    }

                    await channel.BasicAckAsync(ea.DeliveryTag, false);
                };

                await channel.BasicConsumeAsync(QueueName, autoAck: false, consumer: consumer);
                Console.WriteLine($"[+] AuthConsumer listening on {QueueName}");

                await Task.WhenAny(shutdown.Task, Task.Delay(Timeout.Infinite, stoppingToken));
            }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
            {
                break;
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[!] AuthConsumer error ({ex.GetType().Name}): {ex.Message}. Retrying in 5s...");
                await Task.Delay(TimeSpan.FromSeconds(5), stoppingToken);
            }
        }
    }
}
