using System.Text;
using RabbitMQ.Client;
using RabbitMQ.Client.Events;

public class AuthConsumer : BackgroundService{
    private const string QueueName = "q_auth_requests";
    new Dictionary<string, object?> { ["x-queue-type"] = "quorum" }
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

                // Handle at most 10 unacknowledged requests at a time
                await channel.BasicQosAsync(0, 10, false);

                // Quorum queue declaration so it matches server.js and replicates across the RMQ cluster
                var queueArgs = new Dictionary<string, object?> { ["x-queue-type"] = "quorum" };
                await channel.QueueDeclareAsync(QueueName, durable: true, exclusive: false, autoDelete: false, arguments: queueArgs);
                // Wake up when the broker drops the connection, so we reconnect
                var lost = new TaskCompletionSource();
                connection.ConnectionShutdownAsync += (_, _) =>
                {
                    lost.TrySetResult();
                    return Task.CompletedTask;
                };

                var consumer = new AsyncEventingBasicConsumer(channel);
                consumer.ReceivedAsync += async (_, ea) =>
                {
                    try
                    {
                        var request = Encoding.UTF8.GetString(ea.Body.ToArray());

                        using var cts = new CancellationTokenSource(TimeSpan.FromSeconds(6));
                        var response = await _authService.ProcessRequestAsync(request, cts.Token);

                        if (!string.IsNullOrEmpty(ea.BasicProperties.ReplyTo))
                        {
                            var replyProps = new BasicProperties { CorrelationId = ea.BasicProperties.CorrelationId };
                            await channel.BasicPublishAsync("", ea.BasicProperties.ReplyTo, true, replyProps, Encoding.UTF8.GetBytes(response));
                        }

                        await channel.BasicAckAsync(ea.DeliveryTag, false);
                    }
                    catch (Exception ex)
                    {
                        // Drop this message without requeueing, so a bad request can't loop forever
                        Console.WriteLine($"[auth] request failed: {ex.Message}");
                        await channel.BasicNackAsync(ea.DeliveryTag, false, false);
                    }
                };

                await channel.BasicConsumeAsync(QueueName, false, consumer);
                Console.WriteLine($"[auth] Listening on {QueueName}");

                await Task.WhenAny(lost.Task, Task.Delay(Timeout.Infinite, stoppingToken));
            }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
            {
                break;
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[auth] Broker connection failed, retrying in 5s: {ex.Message}");
                await Task.Delay(TimeSpan.FromSeconds(5), stoppingToken);
            }
        }
    }
}
