using RabbitMQ.Client;

public static class RabbitConfig
{
    public static ConnectionFactory CreateFactory() => new()
    {
        UserName = Required("RABBITMQ_USER"),
        Password = Required("RABBITMQ_PASSWORD"),
        VirtualHost = Environment.GetEnvironmentVariable("RABBITMQ_VHOST") is { Length: > 0 } vhost
            ? vhost
            : "messenger",
        RequestedHeartbeat = TimeSpan.FromSeconds(30),
        AutomaticRecoveryEnabled = false
    };

    public static List<AmqpTcpEndpoint> GetEndpoints()
    {
        var raw = Environment.GetEnvironmentVariable("RABBITMQ_HOSTS")
                  ?? Environment.GetEnvironmentVariable("RABBITMQ_HOST")
                  ?? "100.100.36.22,100.117.84.32,100.99.135.24";

        var port = int.TryParse(Environment.GetEnvironmentVariable("RABBITMQ_PORT"), out var p) ? p : 5672;

        return raw.Split(',', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
                  .OrderBy(_ => Random.Shared.Next())
                  .Select(h => new AmqpTcpEndpoint(h, port))
                  .ToList();
    }

    private static string Required(string name) =>
        Environment.GetEnvironmentVariable(name) is { Length: > 0 } value
            ? value
            : throw new InvalidOperationException($"Missing environment variable: {name}");
}