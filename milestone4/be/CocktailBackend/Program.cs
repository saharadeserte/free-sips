var builder = WebApplication.CreateBuilder(args);

builder.Services.AddSingleton<UserStore>();
builder.Services.AddSingleton<DrinkService>();
builder.Services.AddSingleton<AuthService>();
builder.Services.AddHostedService<AuthConsumer>();

var app = builder.Build();

app.MapGet("/", () => "CocktailBackend BE-FE is running.");

await app.RunAsync("http://0.0.0.0:5000");
