using System.Text.Json;
using System.Text.RegularExpressions;

public sealed class IngestionOptions
{
    public bool Enabled { get; set; } = true;
    public string ApiBase { get; set; } = "https://www.thecocktaildb.com/api/json/v1/1/";
    public int StartupDelaySeconds { get; set; } = 10;   // let the relay declare its queue first
    public int IntervalHours { get; set; } = 24;
    public int LookupDelayMs { get; set; } = 300;        // be polite to the free API
    public int RpcTimeoutSeconds { get; set; } = 10;
    public int MaxConsecutiveFailures { get; set; } = 5; // broker or DB is down: stop and retry next cycle
}

public sealed record DrinkIngredient(string Name, string? Measure);

public sealed record DrinkRecord(
    string ExternalId,
    string Name,
    string? Category,
    string? Glass,
    string? Instructions,
    string? ThumbUrl,
    IReadOnlyList<DrinkIngredient> Ingredients);

public sealed class CocktailDbClient
{
    private static readonly Regex NonAlcoholic =
        new(@"non.?alcoholic", RegexOptions.IgnoreCase | RegexOptions.Compiled);

    private readonly HttpClient _http;

    public CocktailDbClient(HttpClient http) => _http = http;

    // filter.php?a=Non_Alcoholic -> list of idDrink
    public async Task<IReadOnlyList<string>> GetNonAlcoholicIdsAsync(CancellationToken ct)
    {
        using var doc = await GetJsonAsync("filter.php?a=Non_Alcoholic", ct);
        var ids = new List<string>();
        foreach (var d in Drinks(doc.RootElement))
            if (Str(d, "idDrink") is { Length: > 0 } id) ids.Add(id);
        return ids;
    }

    // lookup.php?i={id} -> full record. Returns null if the drink is missing or is not
    // marked non-alcoholic by the API (we double-check, the list endpoint is not trusted blindly).
    public async Task<DrinkRecord?> LookupAsync(string id, CancellationToken ct)
    {
        using var doc = await GetJsonAsync($"lookup.php?i={Uri.EscapeDataString(id)}", ct);
        var d = Drinks(doc.RootElement).FirstOrDefault();
        if (d.ValueKind != JsonValueKind.Object) return null;

        if (!NonAlcoholic.IsMatch(Str(d, "strAlcoholic") ?? "")) return null;

        var name = Str(d, "strDrink");
        if (string.IsNullOrWhiteSpace(name)) return null;

        var ingredients = new List<DrinkIngredient>();
        for (var i = 1; i <= 15; i++)
        {
            var ing = Str(d, $"strIngredient{i}");
            if (string.IsNullOrWhiteSpace(ing)) continue;
            ingredients.Add(new DrinkIngredient(ing, Str(d, $"strMeasure{i}")));
        }
        if (ingredients.Count == 0) return null;

        return new DrinkRecord(
            ExternalId: Str(d, "idDrink") ?? id,
            Name: name,
            Category: Str(d, "strCategory"),
            Glass: Str(d, "strGlass"),
            Instructions: Str(d, "strInstructions"),
            ThumbUrl: Str(d, "strDrinkThumb"),
            Ingredients: ingredients);
    }

    // The API returns "drinks": null or "drinks": "None Found" when there is nothing.
    private static IEnumerable<JsonElement> Drinks(JsonElement root) =>
        root.TryGetProperty("drinks", out var arr) && arr.ValueKind == JsonValueKind.Array
            ? arr.EnumerateArray()
            : Enumerable.Empty<JsonElement>();

    private static string? Str(JsonElement e, string name) =>
        e.TryGetProperty(name, out var v) && v.ValueKind == JsonValueKind.String
            ? v.GetString()?.Trim()
            : null;

    private async Task<JsonDocument> GetJsonAsync(string path, CancellationToken ct)
    {
        for (var attempt = 1; ; attempt++)
        {
            try
            {
                using var resp = await _http.GetAsync(path, ct);
                resp.EnsureSuccessStatusCode();
                return JsonDocument.Parse(await resp.Content.ReadAsStringAsync(ct));
            }
            catch (Exception ex) when (attempt < 3 && !ct.IsCancellationRequested
                                       && (ex is HttpRequestException or JsonException or TaskCanceledException))
            {
                await Task.Delay(TimeSpan.FromMilliseconds(500 * attempt), ct);
            }
        }
    }
}
