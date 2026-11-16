using System.Security.Cryptography;
using System.Text.Json;
using BCrypt.Net;

public class AuthService
{
    public record Session(int UserId, string Username);
    private const string InvalidLogin = "Invalid username or password.";

    private readonly UserStore _userStore;
    private readonly DrinkService _drinks;

    public AuthService(UserStore userStore, DrinkService drinks)
    {
        _userStore = userStore;
        _drinks = drinks;
    }

    public async Task<Session?> GetSessionAsync(string? token)
    {
        if (string.IsNullOrEmpty(token)) return null;
        using var dbDoc = JsonDocument.Parse(await _userStore.ValidateSessionAsync(token));
        var db = dbDoc.RootElement;
        if (!IsSuccess(db) || !db.TryGetProperty("user", out var u)) return null;

        var userId = u.GetProperty("user_id").GetInt32();
        var username = u.GetProperty("username").GetString() ?? "";
        return new Session(userId, username);
    }

    public async Task<string> ProcessRequestAsync(string jsonPayload, CancellationToken token)
    {
        try
        {
            using var doc = JsonDocument.Parse(jsonPayload);
            var root = doc.RootElement;
            var action = GetString(root, "action");

            if (action is "register" or "login" or "validate" or "logout")
            {
                return action switch
                {
                    "register" => await RegisterAsync(root),
                    "login"    => await LoginAsync(root),
                    "validate" => await ValidateAsync(root),
                    _          => await LogoutAsync(root),
                };
            }

            var session = await GetSessionAsync(GetString(root, "token"));
            return await _drinks.HandleAsync(action, root, session) ?? Error("Unknown action.");
        }
        catch (JsonException)
        {
            return Error("invalid_input");
        }
        catch (Exception ex)
        {
            // Log the exception type only. The message or payload may contain secrets.
            Console.WriteLine($"[auth] Processing error: {ex.GetType().Name}");
            return Error("authentication_unavailable");
        }
    }

    private async Task<string> RegisterAsync(JsonElement root)
    {
        var username = GetString(root, "username");
        var password = GetString(root, "password");
        var email = GetString(root, "email") ?? "";

        if (string.IsNullOrWhiteSpace(username) || string.IsNullOrEmpty(password))
            return Error("Username and password are required.");

        if (password.Length < 8)
            return Error("Password must be at least 8 characters.");

        // Hash before the password leaves this process
        var hash = BCrypt.Net.BCrypt.HashPassword(password, workFactor: 12);

        using var dbDoc = JsonDocument.Parse(await _userStore.RegisterUserAsync(username, email, hash));
        var db = dbDoc.RootElement;

        if (!IsSuccess(db))
            return Error(ErrorText(db, "Registration failed."));

        return JsonSerializer.Serialize(new
        {
            success = true,
            user = new { username, email }
        });
    }

    private async Task<string> LoginAsync(JsonElement root)
    {
        var username = GetString(root, "username");
        var password = GetString(root, "password");

        if (string.IsNullOrEmpty(username) || string.IsNullOrEmpty(password))
            return Error("Username and password are required.");

        using var dbDoc = JsonDocument.Parse(await _userStore.LookupUserAsync(username));
        var db = dbDoc.RootElement;

        if (!IsSuccess(db))
        {
            // Report a timeout as a timeout, not as a wrong password
            if (ErrorText(db, "") == "database_timeout")
                return Error("database_timeout");
            return Error(InvalidLogin);
        }

        // The database nests the account under "user"
        if (!db.TryGetProperty("user", out var dbUser) || dbUser.ValueKind != JsonValueKind.Object)
            return Error("authentication_unavailable");

        if (dbUser.TryGetProperty("is_active", out var active) && active.ValueKind == JsonValueKind.False)
            return Error(InvalidLogin);

        if (!dbUser.TryGetProperty("password_hash", out var hashEl) || hashEl.ValueKind != JsonValueKind.String ||
            !dbUser.TryGetProperty("user_id", out var idEl) || idEl.ValueKind != JsonValueKind.Number)
        {
            // Field names only, never values
            Console.WriteLine("[auth] DB login reply missing fields. Got: " +
                string.Join(", ", dbUser.EnumerateObject().Select(p => p.Name)));
            return Error("authentication_unavailable");
        }

        var storedHash = hashEl.GetString()!;
        var userId = idEl.GetInt32();
        var isValid = BCrypt.Net.BCrypt.Verify(password, storedHash);

        // Recording the attempt must never block the login itself
        _ = _userStore.SendLoginResultAsync(userId, isValid);

        if (!isValid)
            return Error(InvalidLogin);

        var dbUsername = dbUser.TryGetProperty("username", out var u) && u.ValueKind == JsonValueKind.String
            ? u.GetString()!
            : username;

        // 32 random bytes, hex-encoded so the token is safe in a cookie
        var tokenBytes = new byte[32];
        RandomNumberGenerator.Fill(tokenBytes);
        var sessionToken = Convert.ToHexString(tokenBytes);

        using var sessDoc = JsonDocument.Parse(await _userStore.CreateSessionAsync(userId, sessionToken));
        if (!IsSuccess(sessDoc.RootElement))
            return Error("authentication_unavailable");

        return JsonSerializer.Serialize(new
        {
            success = true,
            token = sessionToken,
            user = new { username = dbUsername }
        });
    }

    // A reply with success=false and no error field means "not logged in".
    // server.js relies on that to tell a logged-out user apart from a backend failure.
    private async Task<string> ValidateAsync(JsonElement root)
    {
        var token = GetString(root, "token");
        var s = await GetSessionAsync(token);
        if (s != null)
            return JsonSerializer.Serialize(new { success = true, user = new { username = s.Username } });

        return JsonSerializer.Serialize(new { success = false });
    }

    private async Task<string> LogoutAsync(JsonElement root)
    {
        var token = GetString(root, "token");
        if (!string.IsNullOrEmpty(token))
            await _userStore.DeleteSessionAsync(token);

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