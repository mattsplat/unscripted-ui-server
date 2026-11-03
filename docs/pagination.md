# Pagination

A paginated list is a [loader](server-methods.md#loaders) that watches a page number.

```blade
<?php
use App\Models\Todo;
use Unscripted\Ui\{Component, Server, State, Url, Watch};

new class extends Component {
    #[State, Url(as: 'q')] public string $query = '';
    #[State, Url] public int $page = 1;
    public array $rows = [];

    #[Server, Watch(['query', 'page'], debounce: 200)]
    public static function rows(string $query, int $page): array
    {
        return Todo::where('title', 'like', "%$query%")
            ->orderBy('id')
            ->paginate(20, ['id', 'title', 'done'], page: $page)
            ->toArray();
    }

    public function next(): void { $this->page++; }
    public function previous(): void { $this->page = max(1, $this->page - 1); }
    protected function updatedQuery(): void { $this->page = 1; }
};
?>

<input type="search" u-model="query">

@foreach ($rows['data'] ?? [] as $row)
    <p u-key="{{ $row['id'] }}">{{ $row['title'] }}</p>
@endforeach

<nav>
    <button u-on:click="previous" @disabled($page <= 1)>Previous</button>
    Page {{ $rows['current_page'] ?? 1 }} of {{ $rows['last_page'] ?? 1 }}
    <button u-on:click="next" @disabled($page >= ($rows['last_page'] ?? 1))>Next</button>
</nav>
```

- **The paginator's `toArray()`** gives `data`, `current_page`, `last_page`, `total` and the
  rest, which the template reads.
- **`#[Url]` on `$page`** keeps it in the address (`?page=3`), so reloading or sharing the link
  keeps the place. The first load, rendered on the server, starts on that page too.
- **Changing `$page`** with an action, or with `u-model.number="page"` on an input, loads that
  page, because the loader watches it.
- **`updatedQuery()`** goes back to page 1 when the search changes.
