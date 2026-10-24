using RabbitMQ.Client;
using RabbitMQ.Client.Events;
using System.Text;


var builder = WebApplication.CreateBuilder(args);
builder.Services.AddHostedService<DatabaseMessaging>();
var app = builder.Build();
RMQCredentials RMQcred = new RMQCredentials();

builder.Configuration.GetSection("RabbitMQ").Bind(RMQcred);

/*var factory = new ConnectionFactory();

factory.UserName = RMQcred.Username;
factory.Password = RMQcred.Password;
factory.VirtualHost = RMQcred.VirtualHost;
factory.HostName = RMQcred.Host;
factory.Port = RMQcred.Port;

using var connection = await factory.CreateConnectionAsync();
using var channel = await connection.CreateChannelAsync();

//Writing to queue

const string testQueueMessage = "Created for Queue";
var body = Encoding.UTF8.GetBytes(testQueueMessage);

await channel.QueueDeclareAsync(queue: RMQcred.Queue, durable: true, exclusive: false, autoDelete: false);

await channel.ExchangeDeclareAsync(exchange: RMQcred.Exchange, type: ExchangeType.Direct);

await channel.QueueBindAsync(queue: RMQcred.Queue, exchange: RMQcred.Exchange, routingKey: RMQcred.RoutingKey);

await channel.BasicPublishAsync(exchange: RMQcred.Exchange, routingKey: RMQcred.RoutingKey, body: body);

Console.WriteLine($"Sent encrypted test message: '{testQueueMessage}'");
//Console.ReadLine();

//Reading queue

Console.WriteLine("Receiving test Message");

var consumer = new AsyncEventingBasicConsumer(channel);
consumer.ReceivedAsync += (model, arg) =>{
    var body = arg.Body.ToArray();
    var message = Encoding.UTF8.GetString(body);
    
    Console.WriteLine($"Recieved message: '{message}'");
    return Task.CompletedTask;
};

await channel.BasicConsumeAsync(queue: RMQcred.Queue, autoAck: true, consumer: consumer);
*/

await app.RunAsync();
//Came with Program.cs when project was created
// Configure the HTTP request pipeline.
/*if (app.Environment.IsDevelopment())
{
    app.MapOpenApi();
}

app.UseHttpsRedirection();

var summaries = new[]
{
    "Freezing", "Bracing", "Chilly", "Cool", "Mild", "Warm", "Balmy", "Hot", "Sweltering", "Scorching"
};

app.MapGet("/weatherforecast", () =>
{
    var forecast =  Enumerable.Range(1, 5).Select(index =>
        new WeatherForecast
        (
            DateOnly.FromDateTime(DateTime.Now.AddDays(index)),
            Random.Shared.Next(-20, 55),
            summaries[Random.Shared.Next(summaries.Length)]
        ))
        .ToArray();
    return forecast;
})
.WithName("GetWeatherForecast");

app.Run();

record WeatherForecast(DateOnly Date, int TemperatureC, string? Summary)
{
    public int TemperatureF => 32 + (int)(TemperatureC / 0.5556);
}*/
