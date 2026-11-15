using RabbitMQ.Client;

// Test: publish one raw request. No web server.
if (args.Contains("--test-auth-publisher"))
{
    await TestAuthPublisher.RunAsync(args);
    return;
}

// Opt-in mock database worker. Runs alone: no relay, no web server.
//   dotnet run --no-launch-profile -- --mock-db
// Stop worker.php first. Both consume q_bedb_rpc.
if (args.Contains("--mock-db"))
{
    Console.WriteLine("[!] MOCK database worker: fake data, in-memory users. Not for production.");
    var mockBuilder = Host.CreateApplicationBuilder(args);
    mockBuilder.Services.AddHostedService<MockDatabaseConsumer>();
    await mockBuilder.Build().RunAsync();
    return;
}

// Normal run: a small web page on port 5260.
// DatabaseMessaging (the relay on q_auth_requests) stays disabled. befe's AuthConsumer
// owns that queue, and two consumers would split login requests between them.
var builder = WebApplication.CreateBuilder(args);
var app = builder.Build();

app.MapGet("/", () => "Backend Database service is running.");

await app.RunAsync();
