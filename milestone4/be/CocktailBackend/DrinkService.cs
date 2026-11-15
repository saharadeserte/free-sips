using System.Text.Json;

// Handles the drink / pantry / recipe / review actions that arrive on q_auth_requests.
//
// Security model:
//  * Only whitelisted fields are copied from the browser's request. A client can never
//    supply its own user_id.
//  * user_id comes from the session (AuthService.GetSession) and is added here.
public class DrinkService
{
    private sealed record Route(string DbAction, bool RequiresLogin, string[] Fields);

    private static readonly Dictionary<string, Route> Routes = new()
    {
        // public (a logged-in user additionally sees their own pending recipes)
        ["drinks_list"]    = new("list_drinks",     false, new[] { "q", "category", "limit", "offset" }),
        ["drink_get"]      = new("get_drink",       false, new[] { "drink_id" }),
        ["categories"]     = new("list_categories", false, Array.Empty<string>()),

        // pantry (bar-shelf inventory) CRUD
        ["pantry_list"]    = new("pantry_list",     true,  Array.Empty<string>()),
        ["pantry_add"]     = new("pantry_add",      true,  new[] { "ingredient", "quantity" }),
        ["pantry_update"]  = new("pantry_update",   true,  new[] { "ingredient", "quantity" }),
        ["pantry_remove"]  = new("pantry_remove",   true,  new[] { "ingredient" }),

        // matching, custom recipes, reviews
        ["recipes_match"]  = new("match_recipes",   true,  new[] { "ingredients", "limit" }),
        ["recipe_submit"]  = new("submit_recipe",   true,  new[] { "name", "category", "glass", "instructions", "ingredients" }),
        ["review_submit"]  = new("submit_review",   true,  new[] { "drink_id", "rating", "comment" }),
        ["review_delete"]  = new("delete_review",   true,  new[] { "drink_id" }),
    };

    private readonly UserStore _store;

    public DrinkService(UserStore store) => _store = store;

    // Returns null if the action is not a drink action.
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

        // The DB reply is already the JSON the frontend expects, so pass it through.
        return await _store.CallAsync(forward);
    }
}
