using RabbitMQ.Client;

if (args.Contains("--test-auth-publisher"))
{
    await TestAuthPublisher.RunAsync(args);
    return;
}

var builder = WebApplication.CreateBuilder(args);
var app = builder.Build();

app.MapGet("/", () => "Backend Database service is running.");

await app.RunAsync();