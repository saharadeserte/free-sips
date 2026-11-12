using System.Text;
using System.Text.Json;
using RabbitMQ.Client;
using RabbitMQ.Client.Events;

public class UserStore
{
    private const string Exchange = "bedb_exchange";
    private const string RoutingKey = "bedb.rpc";
    private static readonly TimeSpan ReplyTimeout = TimeSpan.FromSeconds(5);

    // Sends a request to the database worker and waits for its reply.
    private async Task<string> SendRpcToDatabaseAsync(string payload)
    {
        var factory = RabbitConfig.CreateFactory();
        using var connection = await factory.CreateConnectionAsync();
        using var channel = await connection.CreateChannelAsync();

        // Private, auto-deleted queue for this one request
        var yQueue = (await channel.QueueDeclareAsync("", false, true, true)).QueueName;
        var correlationId = Guid.NewGuid().ToString();
        var props = new BasicProperties { CorrelationId = correlationId, ReplyTo = replyQueue };

        var reply = new TaskCompletionSource<string>(TaskCreationOptions.RunContinuationsAsynchronously);
        var consumer = new AsyncEventingBasicConsumer(channel);
        consumer.ReceivedAsync += (_, ea) =>
        {
            if (ea.BasicProperties.CorrelationId == correlationId)
                reply.TrySetResult(Encoding.UTF8.GetString(ea.Body.ToArray()));
            return Task.CompletedTask;
        };

        await channel.BasicConsumeAsync(replyQueue, true, consumer);
        await channel.BasicPublishAsync(Exchange, RoutingKey, true, props, Encoding.UTF8.GetBytes(payload));

        try
        {
            return await reply.Task.WaitAsync(ReplyTimeout);
        }
        catch (TimeoutException){
            Console.WriteLine("[db-rpc] No reply from database within 5 seconds.");
            return JsonSerializer.Serialize(new { success = false, error = "database_timeout" });
        }
    }

    public Task<string> RegisterUserAsync(string username, string email, string passwordHash){
        var request = JsonSerializer.Serialize(new { action = "register", username, email, password_hash = passwordHash });
        return SendRpcToDatabaseAsync(request);
    }

    public Task<string> LookupUserAsync(string username){
        var request = JsonSerializer.Serialize(new { action = "login", username });
        return SendRpcToDatabaseAsync(request);
    }

    public Task SendLoginResultAsync(int userId, bool success){
    var request = JsonSerializer.Serialize(new { action = "login_result", user_id = userId, success });
    return SendRpcToDatabaseAsync(request); // the worker requires reply_to
}
}
