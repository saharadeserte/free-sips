using RabbitMQ.Client;
using RabbitMQ.Client.Events;
using System.Collections.Concurrent;
using System.Text;

// Test-only. Sends test requests to bedb_exchange exactly like BE-FE would,
// waits for each reply on a private queue, and prints the result.
// Run: DOTNET_ENVIRONMENT=Development dotnet run -- --send-tests
public static class TestRequestSender
{
    public static async Task RunAsync(IConfiguration config)
    {
        var cred = new RMQCredentials();
        config.GetSection("Database").Bind(cred);

        var factory = new ConnectionFactory
        {
            UserName = cred.Username,
            Password = cred.Password,
            VirtualHost = cred.VirtualHost
        };
        var endpoints = cred.GetHosts().Select(h => new AmqpTcpEndpoint(h, cred.Port)).ToList();

        using var connection = await factory.CreateConnectionAsync(endpoints, "test-sender");
        using var channel = await connection.CreateChannelAsync();

        // Private reply queue for this test run (no admin rights needed)
        var replyQueue = (await channel.QueueDeclareAsync(
            "", durable: false, exclusive: true, autoDelete: true)).QueueName;

        var waiting = new ConcurrentDictionary<string, TaskCompletionSource<string>>();

        var consumer = new AsyncEventingBasicConsumer(channel);
        consumer.ReceivedAsync += (_, ea) =>
        {
            var id = ea.BasicProperties.CorrelationId ?? "";
            if (waiting.TryRemove(id, out var tcs))
                tcs.TrySetResult(Encoding.UTF8.GetString(ea.Body.ToArray()));
            return Task.CompletedTask;
        };
        await channel.BasicConsumeAsync(replyQueue, autoAck: true, consumer: consumer);

        var tests = new (string Name, string Payload)[]
        {
            ("1. login unknown user",
             "{\"action\":\"login\",\"identifier\":\"nobody\"}"),
            ("2. register alice",
             "{\"action\":\"register\",\"username\":\"alice\",\"email\":\"alice@example.com\",\"password_hash\":\"abc123\"}"),
            ("3. login alice",
             "{\"action\":\"login\",\"identifier\":\"alice\"}"),
            ("4. register alice again (duplicate)",
             "{\"action\":\"register\",\"username\":\"alice\",\"email\":\"alice@example.com\",\"password_hash\":\"abc123\"}")
        };

        var timeout = TimeSpan.FromSeconds(cred.ReplyTimeoutSeconds + 5);

        foreach (var (name, payload) in tests)
        {
            var corrId = Guid.NewGuid().ToString("N");
            var tcs = new TaskCompletionSource<string>(TaskCreationOptions.RunContinuationsAsynchronously);
            waiting[corrId] = tcs;

            var props = new BasicProperties
            {
                ReplyTo = replyQueue,
                CorrelationId = corrId,
                ContentType = "application/json"
            };

            await channel.BasicPublishAsync(
                cred.SendExchange, cred.SendRoutingKey, false, props,
                Encoding.UTF8.GetBytes(payload));

            var done = await Task.WhenAny(tcs.Task, Task.Delay(timeout));
            Console.WriteLine(name);
            if (done == tcs.Task)
                Console.WriteLine($"   reply: {tcs.Task.Result}");
            else
            {
                waiting.TryRemove(corrId, out _);
                Console.WriteLine("   NO REPLY (BE-DB or the database is not answering)");
            }
            Console.WriteLine();
        }
    }
}