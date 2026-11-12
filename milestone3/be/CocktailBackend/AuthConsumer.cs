using RabbitMQ.Client;
using RabbitMQ.Client.Events;
using System.Text;
using System.Text.Json;

public class AuthConsumer : BackgroundService
{
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
                using var connection = await factory.CreateConnectionAsync();
                using var channel = await connection.CreateChannelAsync();
                using var replyChannel = await connection.CreateChannelAsync();

                await channel.QueueDeclareAsync("q_auth_requests", true, false, false);
                var consumer = new AsyncEventingBasicConsumer(channel);

                consumer.ReceivedAsync += async (model, ea) =>
                {
                    // Strict 6-second processing timeout
                    using var cts = new CancellationTokenSource(TimeSpan.FromSeconds(6));
                    
                    var body = ea.Body.ToArray();
                    var message = Encoding.UTF8.GetString(body);
                    var replyProps = new BasicProperties { CorrelationId = ea.BasicProperties.CorrelationId };

                    // Process request
                    var response = await _authService.ProcessRequestAsync(message, cts.Token);
                    var responseBytes = Encoding.UTF8.GetBytes(response);

                    // Reply directly to the ReplyTo queue
                    if (!string.IsNullOrEmpty(ea.BasicProperties.ReplyTo))
                    {
                        await replyChannel.BasicPublishAsync("", ea.BasicProperties.ReplyTo, true, replyProps, responseBytes);
                    }
                    
                    await channel.BasicAckAsync(ea.DeliveryTag, false);
                };

                await channel.BasicConsumeAsync("q_auth_requests", false, consumer);
                await Task.Delay(Timeout.Infinite, stoppingToken);
            }
            catch (Exception ex)
            {
                Console.WriteLine($"Connection lost. Retrying in 5 seconds... {ex.Message}");
                await Task.Delay(TimeSpan.FromSeconds(5), stoppingToken); // 5-second auto-reconnect
            }
        }
    }
}
