using RabbitMQ.Client;

public static class RabbitConfig
{
    public static ConnectionFactory CreateFactory() => new()
    {
        HostName = Required("RABBITMQ_HOST"),
        UserName = Required("RABBITMQ_USER"),
        Password = Required("RABBITMQ_PASSWORD"),
        VirtualHost = Environment.GetEnvironmentVariable("RABBITMQ_VHOST") is { Length: > 0 } vhost
            ? vhost
            : "messenger",
    };

    private static string Required(string name) =>
        Environment.GetEnvironmentVariable(name) is { Length: > 0 } value
            ? value
            : throw new InvalidOperationException($"Missing environment variable: {name}");
}
