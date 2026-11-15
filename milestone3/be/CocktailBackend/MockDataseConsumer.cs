using System.Collections.Concurrent;
using System.Text;
using System.Text.Json;
using RabbitMQ.Client;
using RabbitMQ.Client.Events;

// TESTING ONLY. Stands in for db/worker.php so the login path can be tested without MariaDB.
// It consumes q_bedb_rpc (bound to bedb_exchange / bedb.rpc), the same queue worker.php uses,
// and answers with the same JSON contract as db/users_auth.php. Users live in memory and
// are lost when the process stops.
//
// Never run it while worker.php is running. Both consume q_bedb_rpc, and RabbitMQ splits
// requests between them, so logins would randomly get fake answers.
//
// Run:  dotnet run --no-launch-profile -- --mock-db
public class MockDatabaseConsumer : BackgroundService
{
    // Must match db/worker.php exactly. Mismatched declare arguments cause PRECONDITION_FAILED.
    private const string AuthExchange = "bedb_exchange";
    private const string AuthRoutingKey = "bedb.rpc";
    private const string AuthQueue = "q_bedb_rpc";

    private readonly RMQCredentials _cred;

    private static readonly ConcurrentDictionary<string, MockUser> Users = new(StringComparer.OrdinalIgnoreCase);
    private static readonly ConcurrentDictionary<string, string> UsernameByEmail = new(StringComparer.OrdinalIgnoreCase);
    private static long _nextId = 0;
    private static readonly object Gate = new();

    private sealed class MockUser
    {
        public long UserId;
        public string Username = "";
        public string Email = "";
        public string PasswordHash = "";
        public int FailedLogins;
        public DateTime? LockedUntil;
    }

    public MockDatabaseConsumer(IConfiguration config)
    {
        _cred = new RMQCredentials();
        config.GetSection("Database").Bind(_cred);
    }

    protected override async Task ExecuteAsync(CancellationToken token)
    {
        // RMQConfig.cs falls back to a placeholder password. Refuse to start rather than
        // fail with a confusing ACCESS_REFUSED error.
        if (string.IsNullOrEmpty(Environment.GetEnvironmentVariable("RABBITMQ_PASSWORD")))
            throw new InvalidOperationException(
                "RABBITMQ_PASSWORD is not set. Set it in the shell before running --mock-db.");

        var factory = new ConnectionFactory
        {
            UserName = _cred.Username,
            Password = _cred.Password,
            VirtualHost = _cred.VirtualHost,
            RequestedHeartbeat = TimeSpan.FromSeconds(30),
            AutomaticRecoveryEnabled = false
        };

        var hosts = _cred.GetHosts();
        Console.WriteLine($"[MOCK DB] RabbitMQ nodes: {string.Join(", ", hosts)}");
        Console.WriteLine($"[MOCK DB] queue '{AuthQueue}' bound to '{AuthExchange}' / '{AuthRoutingKey}'");

        while (!token.IsCancellationRequested)
        {
            try
            {
                var endpoints = hosts.Select(h => new AmqpTcpEndpoint(h, _cred.Port)).ToList();
                using var connection = await factory.CreateConnectionAsync(endpoints, "mock-db", token);
                using var channel = await connection.CreateChannelAsync();

                // Same declarations as db/worker.php: durable direct exchange, durable queue, bound on bedb.rpc.
                await channel.ExchangeDeclareAsync(AuthExchange, ExchangeType.Direct, durable: true, autoDelete: false);
                await channel.QueueDeclareAsync(AuthQueue, durable: true, exclusive: false, autoDelete: false);
                await channel.QueueBindAsync(AuthQueue, AuthExchange, AuthRoutingKey);
                await channel.BasicQosAsync(0, 1, false);

                var shutdown = new TaskCompletionSource();
                connection.ConnectionShutdownAsync += (s, e) => { shutdown.TrySetResult(); return Task.CompletedTask; };

                var consumer = new AsyncEventingBasicConsumer(channel);
                consumer.ReceivedAsync += async (_, ea) =>
                {
                    try
                    {
                        var json = Encoding.UTF8.GetString(ea.Body.ToArray());
                        // Logs the action only. The body can contain password hashes.
                        Console.WriteLine($"[MOCK DB] request action: {ActionOf(json)}");

                        object response;
                        try { response = Handle(json); }
                        catch (JsonException) { response = new { success = false, error = "invalid_json" }; }

                        var replyTo = ea.BasicProperties.ReplyTo;
                        if (string.IsNullOrWhiteSpace(replyTo))
                        {
                            await channel.BasicNackAsync(ea.DeliveryTag, false, false);
                            return;
                        }

                        var props = new BasicProperties
                        {
                            CorrelationId = ea.BasicProperties.CorrelationId,
                            ContentType = "application/json"
                        };
                        var body = Encoding.UTF8.GetBytes(JsonSerializer.Serialize(response));
                        await channel.BasicPublishAsync("", replyTo, false, props, body);
                        await channel.BasicAckAsync(ea.DeliveryTag, false);
                        Console.WriteLine("[MOCK DB] replied");
                    }
                    catch (Exception ex)
                    {
                        Console.WriteLine($"[MOCK DB] error: {ex.GetType().Name}");
                        try { await channel.BasicNackAsync(ea.DeliveryTag, false, false); } catch { }
                    }
                };

                await channel.BasicConsumeAsync(AuthQueue, autoAck: false, consumer: consumer);
                Console.WriteLine($"[MOCK DB] connected to {connection.Endpoint.HostName}");

                await Task.WhenAny(shutdown.Task, Task.Delay(Timeout.Infinite, token));
            }
            catch (OperationCanceledException) when (token.IsCancellationRequested) { break; }
            catch (Exception ex)
            {
                Console.WriteLine($"[MOCK DB] RabbitMQ error: {ex.Message}");
                await Task.Delay(TimeSpan.FromSeconds(5), token);
            }
        }
    }

    private static string ActionOf(string json)
    {
        try
        {
            using var doc = JsonDocument.Parse(json);
            return Str(doc.RootElement, "action") ?? "?";
        }
        catch (JsonException)
        {
            return "invalid_json";
        }
    }

    private static object Handle(string json)
    {
        using var doc = JsonDocument.Parse(json);
        var root = doc.RootElement;
        var action = Str(root, "action")?.ToLowerInvariant();

        return action switch
        {
            "register" => Register(root),
            "login" => Login(root),
            "login_result" => LoginResult(root),
            "update_password_hash" => new { success = true },
            _ => new { success = false, error = "unknown_action" }
        };
    }

    private static object Register(JsonElement r)
    {
        var username = (Str(r, "username") ?? "").Trim();
        var email = (Str(r, "email") ?? "").Trim();
        var hash = Str(r, "password_hash") ?? "";

        if (username == "" || email == "" || hash == "")
            return new { success = false, error = "missing_fields" };

        lock (Gate)
        {
            if (Users.ContainsKey(username) || UsernameByEmail.ContainsKey(email))
                return new { success = false, error = "username_or_email_taken" };

            var user = new MockUser
            {
                UserId = ++_nextId,
                Username = username,
                Email = email,
                PasswordHash = hash
            };
            Users[username] = user;
            UsernameByEmail[email] = username;
            return new { success = true, user_id = user.UserId, username };
        }
    }

    private static MockUser? Find(string identifier)
    {
        if (Users.TryGetValue(identifier, out var u)) return u;
        if (UsernameByEmail.TryGetValue(identifier, out var name) && Users.TryGetValue(name, out var byEmail))
            return byEmail;
        return null;
    }

    private static object Login(JsonElement r)
    {
        var identifier = (Str(r, "identifier") ?? Str(r, "username") ?? Str(r, "email") ?? "").Trim();
        if (identifier == "")
            return new { success = false, error = "missing_identifier" };

        var u = Find(identifier);
        if (u is null)
            return new { success = false, error = "user_not_found" };

        // Like the real worker: the backend verifies the password against password_hash.
        return new
        {
            success = true,
            user = new
            {
                user_id = u.UserId,
                username = u.Username,
                email = u.Email,
                password_hash = u.PasswordHash,
                role = "user",
                is_active = true,
                failed_logins = u.FailedLogins,
                locked_until = u.LockedUntil?.ToString("yyyy-MM-dd HH:mm:ss")
            }
        };
    }

    private static object LoginResult(JsonElement r)
    {
        if (!r.TryGetProperty("success", out var s) ||
            (s.ValueKind != JsonValueKind.True && s.ValueKind != JsonValueKind.False))
            return new { success = false, error = "invalid_success_value" };

        MockUser? u = null;
        if (r.TryGetProperty("user_id", out var idNode) && idNode.TryGetInt64(out var id))
            u = Users.Values.FirstOrDefault(x => x.UserId == id);
        else if (Str(r, "username") is { Length: > 0 } name)
            Users.TryGetValue(name, out u);
        else
            return new { success = false, error = "missing_user_identifier" };

        if (u is null)
            return new { success = false, error = "user_not_found" };

        lock (Gate)
        {
            if (s.ValueKind == JsonValueKind.True)
            {
                u.FailedLogins = 0;
                u.LockedUntil = null;
            }
            else
            {
                u.FailedLogins++;
                if (u.FailedLogins >= 5) u.LockedUntil = DateTime.UtcNow.AddMinutes(15);
            }
        }
        return new { success = true };
    }

    private static string? Str(JsonElement root, string name) =>
        root.TryGetProperty(name, out var n) && n.ValueKind == JsonValueKind.String ? n.GetString() : null;
}
