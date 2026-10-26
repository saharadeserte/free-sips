using RabbitMQ.Client;

var builder = WebApplication.CreateBuilder(args);
builder.Services.AddHostedService<DatabaseMessaging>();

var app = builder.Build();
await app.RunAsync();