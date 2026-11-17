using System.Text.Json;
using static AuthService;

public class DrinkService
{
    private sealed record Route(string DbAction, bool RequiresLogin, string[] Fields);

    private static readonly Dictionary<string, Route> Routes = new()
    {
        // public (a logged-in user additionally sees their own pending recipes on drinks_list / drink_get)
        ["drinks_list"]         = new("list_drinks",         false, new[] { "q", "category", "tag", "limit", "offset" }),
        ["drink_get"]           = new("get_drink",           false, new[] { "drink_id" }),
        ["categories"]          = new("list_categories",     false, Array.Empty<string>()),
        ["drinks_random"]       = new("random_drinks",       false, new[] { "limit", "with_image" }),
        ["suggest_drinks"]      = new("suggest_drinks",      false, new[] { "q" }),
        ["suggest_ingredients"] = new("suggest_ingredients", false, new[] { "q" }),

        // pantry (bar-shelf inventory) CRUD
        ["pantry_list"]         = new("pantry_list",         true,  Array.Empty<string>()),
        ["pantry_add"]          = new("pantry_add",          true,  new[] { "ingredient", "quantity" }),
        ["pantry_update"]       = new("pantry_update",       true,  new[] { "ingredient", "quantity" }),
        ["pantry_remove"]       = new("pantry_remove",       true,  new[] { "ingredient" }),

        // matching, custom recipes, reviews
        ["recipes_match"]       = new("match_recipes",       true,  new[] { "ingredients", "limit" }),
        ["recipe_submit"]       = new("submit_recipe",       true,  new[] { "name", "category", "glass", "instructions", "thumb_url", "ingredients" }),
        ["review_submit"]       = new("submit_review",       true,  new[] { "drink_id", "rating", "comment" }),
        ["review_delete"]       = new("delete_review",       true,  new[] { "drink_id" }),
    };

    private readonly UserStore _store;

    public DrinkService(UserStore store) => _store = store;

    public static bool SkipsSessionLookup(string? action) =>
        action is "drinks_random" or "suggest_drinks" or "suggest_ingredients" or "categories";

    public async Task<string?> HandleAsync(string? action, JsonElement root, Session? session)
    {
        if (action is null || !Routes.TryGetValue(action, out var route)) return null;

        if (route.RequiresLogin && session is null)
            return JsonSerializer.Serialize(new { success = false, error = "not_logged_in" });

        var forward = new Dictionary<string, object?> { ["action"] = route.DbAction };
        foreach (var field in route.Fields)
            if (root.TryGetProperty(field, out var value))
                forward[field] = value.Clone();

        if (session is not null) forward["user_id"] = session.UserId;

        return await _store.CallAsync(forward);
    }
}