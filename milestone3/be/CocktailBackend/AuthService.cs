using System.Collections.Concurrent;
using System.Security.Cryptography;
using System.Text.Json;
using BCrypt.Net;

public class AuthService
{
    private readonly UserStore _userStore;

    // token -> username. In memory, so sessions are lost when the backend restarts.
    private readonly ConcurrentDictionary<string, string> _sessions = new();

    private const string InvalidLogin = "Invalid username or password.";

    public AuthService(UserStore userStore)
    {
        _userStore = userStore;
    }

    public async Task<string> ProcessRequestAsync(string jsonPayload, CancellationToken token)
    {
        try
        {
            using var doc = JsonDocument.Parse(jsonPayload);
            var root = doc.RootElement;
            var action = GetString(root, "action");

            switch (action)
            {
                case "register":
                    return await RegisterAsync(root);
                case "login":
                    return await LoginAsync(root);
                case "validate":
                    return Validate(root);
                case "logout":
                    return Logout(root);
                default:
                    return Error("Unknown action.");
            }
        }
        catch (Exception ex)
        {
            Console.WriteLine($"[auth] Bad request: {ex.Message}");
            return Error("Invalid request.");
        }
    }

    private async Task<string> RegisterAsync(JsonElement root)
    {
        var username = GetString(root, "username");
        var password = GetString(root, "password");
        var email = GetString(root, "email") ?? "";

        if (string.IsNullOrWhiteSpace(username) || string.IsNullOrEmpty(password))
            return Error("Username and password are required.");

        // Hash before it leaves this process. The DB never sees plaintext.
        var hash = BCrypt.Net.BCrypt.HashPassword(password, workFactor: 12);

        var dbJson = JsonDocument.Parse(await _userStore.RegisterUserAsync(username, email, hash)).RootElement;
        if (!IsSuccess(dbJson))
            return Error(ErrorText(dbJson, "Registration failed."));

        return JsonSerializer.Serialize(new { success = true, user = new { username, email } });
    }

    private async Task<string> LoginAsync(JsonElement root) {
        var username = GetString(root, "username");
        var password = GetString(root, "password");

        if (string.IsNullOrEmpty(username) || string.IsNullOrEmpty(password))
            return Error("Username and password are required.");

        using var dbDoc = JsonDocument.Parse(await _userStore.LookupUserAsync(username));
        var dbJson = dbDoc.RootElement;

        if (!IsSuccess(dbJson)){
            if (ErrorText(dbJson, "") == "database_timeout")
                return Error("database_timeout");
            return Error(InvalidLogin);
        }

        if (!dbJson.TryGetProperty("user", out var dbUser) || dbUser.ValueKind != JsonValueKind.Object)
            return Error("authentication_unavailable");

        if (dbUser.TryGetProperty("is_active", out var active) && active.ValueKind == JsonValueKind.False)
            return Error(InvalidLogin);

        var storedHash = dbUser.GetProperty("password_hash").GetString();
        var userId = dbUser.GetProperty("user_id").GetInt32();
        var isValid = BCrypt.Net.BCrypt.Verify(password, storedHash);

        try
        {
            await _userStore.SendLoginResultAsync(userId, isValid);
        }
        catch (Exception ex)
        {
            // Logging the attempt should never block the login itself
            Console.WriteLine($"[auth] Could not record login result: {ex.Message}");
        }

        if (!isValid)
            return Error(InvalidLogin);

        var dbUsername = dbUser.TryGetProperty("username", out var u) ? u.GetString() : username;

        // 32 random bytes, hex-encoded so the token is safe in a cookie with no escaping
        var tokenBytes = new byte[32];
        RandomNumberGenerator.Fill(tokenBytes);
        var token = Convert.ToHexString(tokenBytes);

        _sessions[token] = dbUsername!;

        return JsonSerializer.Serialize(new
        {
            success = true,
            token,
            user = new { username = dbUsername }
        });
    }

    // A reply with success=false and no error field means "not logged in".
    // server.js relies on that to tell a logged-out user apart from a backend failure.
    private string Validate(JsonElement root)
    {
        var token = GetString(root, "token");
        if (token != null && _sessions.TryGetValue(token, out var username))
            return JsonSerializer.Serialize(new { success = true, user = new { username } });

        return JsonSerializer.Serialize(new { success = false });
    }

    private string Logout(JsonElement root)
    {
        var token = GetString(root, "token");
        if (token != null)
            _sessions.TryRemove(token, out _);

        return JsonSerializer.Serialize(new { success = true });
    }

    private static string? GetString(JsonElement root, string name)
    {
        return root.TryGetProperty(name, out var el) && el.ValueKind == JsonValueKind.String
            ? el.GetString()
            : null;
    }

    private static bool IsSuccess(JsonElement json)
    {
        return json.TryGetProperty("success", out var s) && s.ValueKind == JsonValueKind.True;
    }

    private static string ErrorText(JsonElement json, string fallback)
    {
        return json.TryGetProperty("error", out var e) && e.ValueKind == JsonValueKind.String
            ? e.GetString()!
            : fallback;
    }

    private static string Error(string message)
    {
        return JsonSerializer.Serialize(new { success = false, error = message });
    }
}
