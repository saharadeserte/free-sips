public class RMQCredentials
{
    public string Hosts { get; set; } = Environment.GetEnvironmentVariable("RABBITMQ_HOSTS")
                                        ?? Environment.GetEnvironmentVariable("RABBITMQ_HOST")
                                        ?? "100.100.36.22";

    public string Username { get; set; } = Environment.GetEnvironmentVariable("RABBITMQ_USER") ?? "";
    public string Password { get; set; } = Environment.GetEnvironmentVariable("RABBITMQ_PASSWORD") ?? "";
    public string VirtualHost { get; set; } = Environment.GetEnvironmentVariable("RABBITMQ_VHOST") ?? "messenger";
    public int Port { get; set; } = 5672;

    public string Queue { get; set; } = "";
    public string RoutingKey { get; set; } = "";
    public string Exchange { get; set; } = "";
    public string SendQueue { get; set; } = "";
    public string SendRoutingKey { get; set; } = "";
    public string SendExchange { get; set; } = "";
    public string QueueType { get; set; } = "quorum";
    public int PrefetchCount { get; set; } = 1;
    public int ReplyTimeoutSeconds { get; set; } = 3;

    public string[] GetHosts() =>
        Hosts.Split(',', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries);

    public void Validate()
    {
        if (string.IsNullOrWhiteSpace(Username))
            throw new InvalidOperationException("Missing required environment variable: RABBITMQ_USER");
        if (string.IsNullOrWhiteSpace(Password))
            throw new InvalidOperationException("Missing required environment variable: RABBITMQ_PASSWORD");
    }
}