using RabbitMQ.Client;
using System.Text;

var builder = WebApplication.CreateBuilder(args);
var app = builder.Build();

string rabbitMqHost =
    Environment.GetEnvironmentVariable("RABBITMQ_HOST")
    ?? "100.100.36.22";

string rabbitMqUser =
    Environment.GetEnvironmentVariable("RABBITMQ_USER")
    ?? "guest";

string rabbitMqPassword =
    Environment.GetEnvironmentVariable("RABBITMQ_PASSWORD")
    ?? "guest";

const string queueName = "cocktail_queue";

app.MapGet("/", () =>
{
    return Results.Ok(new
    {
        status = "success",
        message = "Cocktail Backend is running!"
    });
});

app.MapGet("/send", async () =>
{
    try
    {
        var factory = new ConnectionFactory
        {
            HostName = rabbitMqHost,
            UserName = rabbitMqUser,
            Password = rabbitMqPassword
        };

        using var connection = await factory.CreateConnectionAsync();
        using var channel = await connection.CreateChannelAsync();

        await channel.QueueDeclareAsync(
            queue: queueName,
            durable: false,
            exclusive: false,
            autoDelete: false
        );

        string message = "Hello from the Cocktail Backend!";
        var body = Encoding.UTF8.GetBytes(message);

        await channel.BasicPublishAsync(
            exchange: "",
            routingKey: queueName,
            body: body
        );

        return Results.Ok(new
        {
            status = "success",
            message = "Message written to RabbitMQ queue"
        });
    }
    catch (Exception ex)
    {
        return Results.Problem(
            detail: ex.Message,
            statusCode: 500
        );
    }
});

app.MapGet("/read", async () =>
{
    try
    {
        var factory = new ConnectionFactory
        {
            HostName = rabbitMqHost,
            UserName = rabbitMqUser,
            Password = rabbitMqPassword
        };

        using var connection = await factory.CreateConnectionAsync();
        using var channel = await connection.CreateChannelAsync();

        await channel.QueueDeclareAsync(
            queue: queueName,
            durable: false,
            exclusive: false,
            autoDelete: false
        );

        var result = await channel.BasicGetAsync(
            queue: queueName,
            autoAck: true
        );

        if (result == null)
        {
            return Results.Ok(new
            {
                status = "success",
                message = "Queue is empty"
            });
        }

        string message =
            Encoding.UTF8.GetString(result.Body.ToArray());

        return Results.Ok(new
        {
            status = "success",
            message = message
        });
    }
    catch (Exception ex)
    {
        return Results.Problem(
            detail: ex.Message,
            statusCode: 500
        );
    }
});

app.Run("http://0.0.0.0:5000");
