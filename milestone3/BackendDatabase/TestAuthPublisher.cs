
using RabbitMQ.Client;
using RabbitMQ.Client.Events;
using System.Text;
using System.Text.Json;

public static class TestAuthPublisher
{
    public static async Task RunAsync(string[] args)
    {
        var config = new ConfigurationBuilder()
            .SetBasePath(Directory.GetCurrentDirectory())
            .AddJsonFile("appsettings.json", optional: false)
            .AddJsonFile("appsettings.Development.json", optional: true)
            .Build();

        var db = config.GetSection("Database");

        var factory = new ConnectionFactory
        {
            HostName = db["Host"]!,
            Port = db.GetValue<int>("Port"),
            UserName = db["Username"]!,
            Password = db["Password"]!,
            VirtualHost = db["VirtualHost"]!
        };

        var requestQueue = db["SendQueue"]
            ?? throw new InvalidOperationException(
                "Database:SendQueue is missing.");

        using var connection = await factory.CreateConnectionAsync();
        using var channel = await connection.CreateChannelAsync();

        var replyQueue = await channel.QueueDeclareAsync(
            queue: "",
            durable: false,
            exclusive: true,
            autoDelete: true);

        var correlationId = Guid.NewGuid().ToString();

        var replyReceived = new TaskCompletionSource<string>(
            TaskCreationOptions.RunContinuationsAsynchronously);

        var consumer = new AsyncEventingBasicConsumer(channel);

        consumer.ReceivedAsync += (_, ea) =>
        {
            if (ea.BasicProperties.CorrelationId == correlationId)
            {
                var reply = Encoding.UTF8.GetString(ea.Body.ToArray());
                replyReceived.TrySetResult(reply);
            }

            return Task.CompletedTask;
        };

        await channel.BasicConsumeAsync(
            queue: replyQueue.QueueName,
            autoAck: true,
            consumer: consumer);

        var username = "testuser_demo";
        var email = "testuser_demo@example.com";
        var password = "TestPassword123!";
        var passwordHash = BCrypt.Net.BCrypt.HashPassword(password, 12);

        var action = args.Contains("--login-result")
            ? "login_result"
            : args.Contains("--login")
                ? "login"
                : "register";

        var success = !args.Contains("--failure");

        object request = action switch
        {
            "login_result" => new
            {
                action,
                user_id = 3,
                success
            },

            "login" => new
            {
                action,
                identifier = username
            },

            _ => new
            {
                action,
                username,
                email,
                password_hash = passwordHash
            }
        };
    
        var body = Encoding.UTF8.GetBytes(
            JsonSerializer.Serialize(request));

        var properties = new BasicProperties
        {
            ReplyTo = replyQueue.QueueName,
            CorrelationId = correlationId,
            ContentType = "application/json"
        };

        Console.WriteLine($"Sending {action} request to {requestQueue}...");
        Console.WriteLine($"Test username: {username}");

        await channel.BasicPublishAsync(
            exchange: "",
            routingKey: requestQueue,
            mandatory: true,
            basicProperties: properties,
            body: body);

        try
        {
            var reply = await replyReceived.Task.WaitAsync(
                TimeSpan.FromSeconds(10));

            Console.WriteLine("RPC reply received:");
            Console.WriteLine(reply);
        }
        catch (TimeoutException)
        {
            Console.WriteLine(
                "No RPC reply received within 10 seconds.");
        }
    }
}
