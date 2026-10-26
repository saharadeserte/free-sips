public class RMQCredentials
{
    public string Host { get; set; } =
        Environment.GetEnvironmentVariable("RABBITMQ_HOST") ?? "100.100.36.22";
    public string Username { get; set; } =
        Environment.GetEnvironmentVariable("RABBITMQ_USER") ?? "bigboss";
    public string Password { get; set; } =
        Environment.GetEnvironmentVariable("RABBITMQ_PASSWORD") ?? "secure";
    public string VirtualHost { get; set; } = "messenger";
    public int Port { get; set; } = 5672;

    public string Queue { get; set; } = "";
    public string RoutingKey { get; set; } = "";
    public string Exchange { get; set; } = "";
    public string SendQueue { get; set; } = "";
    public string SendRoutingKey { get; set; } = "";
    public string SendExchange { get; set; } = "";
}