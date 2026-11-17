using RabbitMQ.Client;

// Test: publish one raw request. No web server.
if (args.Contains("--test-auth-publisher"))
{
    await TestAuthPublisher.RunAsync(args);
    return;
}
if (args.Contains("--send-tests"))
{
    var testBuilder = Host.CreateApplicationBuilder(args);
    await TestRequestSender.RunAsync(testBuilder.Configuration);
    return;
}

// Opt-in mock database worker. Runs alone: no relay, no web server.
//   dotnet run --no-launch-profile -- --mock-db
// Stop worker.php first. Both consume q_bedb_rpc.
// Normal run: a small web page on port 5260.
// DatabaseMessaging (the relay on q_auth_requests) stays disabled. befe's AuthConsumer
// owns that queue, and two consumers would split login requests between them.

var builder = WebApplication.CreateBuilder(args);

// Ensure the RabbitMQ background consumer is registered
builder.Services.AddHostedService<DatabaseMessaging>();
var ingest = builder.Configuration.GetSection("Ingestion").Get<IngestionOptions>() ?? new IngestionOptions();
builder.Services.AddSingleton(ingest);
builder.Services.AddHttpClient<CocktailDbClient>(c =>
{
    c.BaseAddress = new Uri(ingest.ApiBase);
    c.Timeout = TimeSpan.FromSeconds(15);
    c.DefaultRequestHeaders.UserAgent.ParseAdd("FreeSpiritSips-IT490/1.0");
});
builder.Services.AddSingleton<DbRpcClient>();
builder.Services.AddHostedService<DrinkIngestionWorker>();

var app = builder.Build();

app.MapGet("/", () => "Backend Database service is running.");

await app.RunAsync();
