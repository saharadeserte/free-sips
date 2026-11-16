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
        using var connection = await factory.CreateConnectionAsync(
            RabbitConfig.GetEndpoints(), $"be-fe-rpc-{Environment.MachineName}");
        using var channel = await connection.CreateChannelAsync();
        await channel.ExchangeDeclareAsync("bedb_exchange", ExchangeType.Direct, durable: true);
        
        // Private, auto-deleted reply queue for this one request
        var replyQueue = (await channel.QueueDeclareAsync("", false, true, true)).QueueName;
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

        // Subscribe before publishing so the reply can't arrive before we're listening
        await channel.BasicConsumeAsync(replyQueue, true, consumer);
        await channel.BasicPublishAsync(Exchange, RoutingKey, true, props, Encoding.UTF8.GetBytes(payload));

        try
        {
            return await reply.Task.WaitAsync(ReplyTimeout);
        }
        catch (TimeoutException)
        {
            // Never log the payload: register requests contain password hashes
            Console.WriteLine("[db-rpc] No reply from database within 5 seconds.");
            return JsonSerializer.Serialize(new { success = false, error = "database_timeout" });
        }
    }
    public Task<string> CreateSessionAsync(int userId, string token) =>
        SendRpcToDatabaseAsync(JsonSerializer.Serialize(new { action = "session_create", user_id = userId, token }));

    public Task<string> ValidateSessionAsync(string token) =>
        SendRpcToDatabaseAsync(JsonSerializer.Serialize(new { action = "session_validate", token }));

    public Task<string> DeleteSessionAsync(string token) =>
        SendRpcToDatabaseAsync(JsonSerializer.Serialize(new { action = "session_delete", token }));

    public Task<string> RegisterUserAsync(string username, string email, string passwordHash)
    {
        var request = JsonSerializer.Serialize(new { action = "register", username, email, password_hash = passwordHash });
        return SendRpcToDatabaseAsync(request);
    }

    public Task<string> LookupUserAsync(string username)
    {
        var request = JsonSerializer.Serialize(new { action = "login", username });
        return SendRpcToDatabaseAsync(request);
    }

    // The worker requires reply_to, so this is still an RPC. The login doesn't
    // wait for it: the reply is ignored and a failure is only logged.
    public Task SendLoginResultAsync(int userId, bool success){
        var request = JsonSerializer.Serialize(new { action = "login_result", user_id = userId, success });

        _ = Task.Run(async () =>
        {
            try
            {
                await SendRpcToDatabaseAsync(request);
            }
            catch (Exception ex)
            {
                Console.WriteLine($"[db-rpc] login_result failed: {ex.Message}");
            }
        });

        return Task.CompletedTask;
    }

    // Generic RPC to the database worker. Callers must never pass raw browser input as `request`.
    // So DrinkService can reuse the existing code 
    public Task<string> CallAsync(object request) =>
        SendRpcToDatabaseAsync(JsonSerializer.Serialize(request));
}
