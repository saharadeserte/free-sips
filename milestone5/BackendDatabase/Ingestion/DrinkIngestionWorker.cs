using System.Text.Json;

// Pulls the non-alcoholic drink list from TheCocktailDB, looks up each drink, and sends it to
// MariaDB through bedb.rpc as an idempotent "upsert_drink". Re-running is safe: drinks are keyed
// on TheCocktailDB's idDrink, so a second pass updates rows instead of duplicating them.
public sealed class DrinkIngestionWorker : BackgroundService
{
    private readonly CocktailDbClient _api;
    private readonly DbRpcClient _rpc;
    private readonly IngestionOptions _opt;

    public DrinkIngestionWorker(CocktailDbClient api, DbRpcClient rpc, IngestionOptions opt)
    {
        _api = api;
        _rpc = rpc;
        _opt = opt;
    }

    protected override async Task ExecuteAsync(CancellationToken ct)
    {
        if (!_opt.Enabled)
        {
            Console.WriteLine("[ingest] disabled (Ingestion:Enabled=false)");
            return;
        }

        try
        {
            await Task.Delay(TimeSpan.FromSeconds(_opt.StartupDelaySeconds), ct);

            while (!ct.IsCancellationRequested)
            {
                try
                {
                    await RunOnceAsync(ct);
                }
                catch (Exception ex) when (!ct.IsCancellationRequested)
                {
                    Console.WriteLine($"[ingest] run aborted: {ex.Message}");
                }

                await Task.Delay(TimeSpan.FromHours(_opt.IntervalHours), ct);
            }
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
            // shutting down
        }
    }

    private async Task RunOnceAsync(CancellationToken ct)
    {
        Console.WriteLine("[ingest] fetching non-alcoholic drink list...");
        var ids = await _api.GetNonAlcoholicIdsAsync(ct);
        Console.WriteLine($"[ingest] {ids.Count} drinks to process");

        int stored = 0, skipped = 0, failed = 0, consecutiveFailures = 0;

        foreach (var id in ids)
        {
            ct.ThrowIfCancellationRequested();

            try
            {
                var drink = await _api.LookupAsync(id, ct);
                if (drink is null)
                {
                    skipped++;
                    continue;
                }

                using var reply = await _rpc.CallAsync(ToRequest(drink), ct);
                if (reply.RootElement.TryGetProperty("success", out var ok) && ok.ValueKind == JsonValueKind.True)
                {
                    stored++;
                    consecutiveFailures = 0;
                }
                else
                {
                    failed++;
                    var err = reply.RootElement.TryGetProperty("error", out var e) ? e.ToString() : "unknown";
                    Console.WriteLine($"[ingest] {id} '{drink.Name}' rejected: {err}");
                    // A rejected drink is a data problem, not an outage. Do not count it.
                    if (err is "database_timeout" or "database_error" or "request_processing_failed")
                        consecutiveFailures++;
                }
            }
            catch (Exception ex) when (ex is not OperationCanceledException || !ct.IsCancellationRequested)
            {
                failed++;
                consecutiveFailures++;
                Console.WriteLine($"[ingest] {id} failed: {ex.Message}");
            }

            if (consecutiveFailures >= _opt.MaxConsecutiveFailures)
                throw new InvalidOperationException(
                    $"{consecutiveFailures} failures in a row (stored {stored}, skipped {skipped}, failed {failed}); will retry next cycle");

            await Task.Delay(_opt.LookupDelayMs, ct);
        }

        Console.WriteLine($"[ingest] done: stored {stored}, skipped {skipped}, failed {failed}");
    }

    private static object ToRequest(DrinkRecord d) => new
    {
        action = "upsert_drink",
        external_id = d.ExternalId,
        name = d.Name,
        category = d.Category,
        glass = d.Glass,
        instructions = d.Instructions,
        thumb_url = d.ThumbUrl,
        non_alcoholic = true,
        ingredients = d.Ingredients.Select(i => new { name = i.Name, measure = i.Measure })
    };
}
