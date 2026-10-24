public class RMQCredentials{
    public string Username { get; set; } = "";
    public string Password { get; set; } = "";
    public string VirtualHost { get; set; } = "";
    public string Host { get; set; } = "";
    public int Port { get; set; } = 5672;
    public string Queue { get; set; } = "";
    public string RoutingKey { get; set; } = ""; 
    public string Exchange { get; set; } = "";
}