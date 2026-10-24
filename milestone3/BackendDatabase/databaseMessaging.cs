using RabbitMQ.Client;
using RabbitMQ.Client.Events;
using System.Text;

public class DatabaseMessaging : BackgroundService{

    private RMQCredentials RMQcred = new RMQCredentials();
    private IConfiguration IConfig;
    
    public DatabaseMessaging(IConfiguration config){
        IConfig = config;
        IConfig.GetSection("Database").Bind(RMQcred);
    }

    protected override async Task ExecuteAsync(CancellationToken token){
        var factory = new ConnectionFactory();
        
        factory.UserName = RMQcred.Username;
        factory.Password = RMQcred.Password;
        factory.VirtualHost = RMQcred.VirtualHost;
        factory.HostName = RMQcred.Host;
        factory.Port = RMQcred.Port;

        while(!token.IsCancellationRequested){
            
            try{
                using var connection = await factory.CreateConnectionAsync();
                using var channel = await connection.CreateChannelAsync();

                await channel.QueueDeclareAsync(queue: RMQcred.Queue, durable: true, exclusive: false, autoDelete: false);
                await channel.ExchangeDeclareAsync(exchange: RMQcred.Exchange, type: ExchangeType.Direct);
                await channel.QueueBindAsync(queue: RMQcred.Queue, exchange: RMQcred.Exchange, routingKey: RMQcred.RoutingKey);
                Console.WriteLine("Connection Succeeded");

                var consumer = new AsyncEventingBasicConsumer(channel);

                consumer.ReceivedAsync += (model, arg) =>{
                var body = arg.Body.ToArray();
                var message = Encoding.UTF8.GetString(body);
                
                Console.WriteLine($"Recieved message: '{message}'");
                return Task.CompletedTask;
                };

                await channel.BasicConsumeAsync(queue: RMQcred.Queue, autoAck: true, consumer: consumer);
                await Task.Delay(Timeout.Infinite, token);
            }
            catch(RabbitMQ.Client.Exceptions.BrokerUnreachableException ex){
                Console.WriteLine("Connection Failed");
                Console.WriteLine(ex.ToString());
                await Task.Delay(TimeSpan.FromSeconds(5), token);
            }
        }
    }

}