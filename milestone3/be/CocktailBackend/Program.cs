using Microsoft.AspNetCore.Builder;
using Microsoft.Extensions.DependencyInjection;

var builder = WebApplication.CreateBuilder(args);

// Register the Authentication and RPC background services
builder.Services.AddSingleton<AuthService>();
builder.Services.AddSingleton<UserStore>();
builder.Services.AddHostedService<AuthConsumer>();

var app = builder.Build();

// Health check endpoint required by start_be-fe.sh on port 5000
app.MapGet("/", () => "CocktailBackend BE-FE is running!");

app.Run("http://0.0.0.0:5000");