var builder = WebApplication.CreateBuilder(args);

// 1. Register services BEFORE builder.Build()
builder.Services.AddSingleton<UserStore>();
builder.Services.AddSingleton<DrinkService>();
builder.Services.AddSingleton<AuthService>();
builder.Services.AddHostedService<AuthConsumer>();

var app = builder.Build();

// Optional CLI test publisher (only keep if TestAuthPublisher.cs is in this project)
if (args.Contains("--test-auth-publisher"))
{
    await TestAuthPublisher.RunAsync(args);
    return;
}

// Health check endpoint required by start_be-fe.sh on port 5000
app.MapGet("/", () => "CocktailBackend BE-FE is running.");

await app.RunAsync("http://0.0.0.0:5000");