# Reference

## HTML attributes

| Attribute | Does | More |
|---|---|---|
| `u-on:<event>="action"` | Calls the action on that DOM event: `click`, `submit`, `input`, `keydown`... | [Actions](actions.md) |
| `u-on:click="move(1, -1)"` | With arguments, as JSON literals. Strings in double quotes, so single-quote the attribute | [Actions](actions.md#calling-actions) |
| `u-on:...` modifiers | `.prevent`, `.stop`, `.self`, `.once`, `.window`, `.outside`, keys (`.enter`, `.escape`, `.space`, `.tab`, `.up`, `.down`, `.left`, `.right`, `.delete`, `.backspace`, one character), `.ctrl`, `.shift`, `.alt`, `.meta` | [Actions](actions.md#modifiers) |
| `u-model="name"` | Binds an input to a `#[State]` property, both ways. `todos.3.done` binds a path into an array | [Forms](forms.md) |
| `u-model.blur`, `.debounce.300ms` | Writes on leaving the field, or after a pause (250 ms by default) | [Forms](forms.md#binding-inputs) |
| `u-model.number`, `.boolean` | Writes a number, or a bool | [Forms](forms.md#binding-inputs) |
| `u-model` on `<input type="file">` | Uploads as soon as a file is chosen | [File uploads](file-uploads.md) |
| `u-key="{{ $id }}"` | Which item an element is, so re-renders keep it as lists change. `id` works too | [How it works](how-it-works.md#rendering) |
| `u-loading="action"` | Shown only while the action or loader waits on the server; with no value, while anything does. `.remove` hides instead | [Loading states](loading-states.md) |
| `u-offline` | Shown only while the browser is offline | [Loading states](loading-states.md#offline) |
| `u-poll.5s="action"` | Calls the action on a timer while the element is on the page (2 s by default) | [Polling](polling.md) |
| `u-init="action"` | Calls the action once, when the element first appears | [Polling](polling.md#once-when-it-appears) |
| `u-confirm="Sure?"` | With a `u-on` on the same element: asks first | [Actions](actions.md#asking-first) |
| `u-ignore` | Re-renders leave the element alone, children and attributes | [Events](events.md#with-javascript) |
| `u-navigate` | On a link: goes to the page without reloading PHP | [Navigation](navigation.md) |
| `u-persist="id"` | Kept as it is across `u-navigate` page changes | [Navigation](navigation.md#keeping-an-element) |

## Blade directives

| Directive | Where | Does |
|---|---|---|
| `@unscripted('name', $options)` | Your Laravel views | Puts a component on the page, rendered on the server. Options: `id`, `lazy`, `placeholder` |
| `@child('name', $props, $key)` | Component templates | A [nested component](nested-components.md) |
| `@include('partial', $vars)`, `<x-partial>` | Component templates | A [partial](components.md#partials) |
| `@props([...])` | Partials | Defaults for a partial's variables |
| `@error('field') ... @enderror` | Component templates | A field's first error, as `$message` |
| `@class`, `@checked`, `@selected`, `@disabled`, `@readonly`, `@required` | Component templates | Attributes from conditions |
| `@if`, `@foreach`, `@forelse`, `@for`, `@while`, `@unless`, `@isset`, `@empty`, `@php`, `@json`, `@verbatim` | Component templates | As in Laravel's Blade |

## PHP attributes

All in `Unscripted\Ui`.

| Attribute | On | Does |
|---|---|---|
| `#[State]` | A public property | `u-model` may write it |
| `#[Computed]` | A method | Read as `$this->name`, run at most once per render |
| `#[Url(as: 'q')]` | A property | Kept in the query string |
| `#[Server(can: 'ability')]` | A public static method | Runs on the server; `can` checks a Gate ability first |
| `#[Watch('prop', debounce: 300)]` | A `#[Server]` method | A loader: fills the property of its name, on mount and when the watched properties change |
| `#[Validate('rules', as: 'path')]` | A `#[Server]` method's parameter | Laravel validation before the method runs; checked in the browser too, by `as` path |
| `#[Live('key', poll: 5000)]` | A loader | Runs again on every page when the server calls `Live::changed('key')` |
| `#[Live('key')]` | A browser method | Called with the data on every page when the server calls `Live::send('key', $data)` |
| `#[On('event')]` | A method | Called when the event is dispatched, by a component or JavaScript. Repeatable |

## Component methods

| Method | Does |
|---|---|
| `$this->refresh()` | Renders again, for state changed outside an action |
| `$this->dispatch('event', $detail)` | A `CustomEvent` on `window`, for `#[On]` and JavaScript |
| `$this->redirect('/url')` | Goes to another page |
| `$this->validate(?array $only)` | Checks fields against their server methods' rules in the browser; whether they passed |
| `$this->errors()` | The error bag: `has`, `first`, `get`, `all`, `any`, `add`, `clear` |
| `$this->loading(?string $name)` | Whether that action or loader, or any, waits on the server |
| `$this->progress('path')` | An upload's percent done |

Hooks: `mounted`, `unmounted`, `rendering`, `rendered`, `updating<Name>`, `updated<Name>`,
`updated`, `failed`. See [lifecycle hooks](lifecycle-hooks.md).

## Server classes

| | |
|---|---|
| `Unscripted\UiServer\Live::changed('key')` | Reruns every page's `#[Live('key')]` loader |
| `Unscripted\UiServer\Live::send('key', $data)` | Calls every page's `#[Live('key')]` browser method with the data |
| `Unscripted\UiServer\Upload::find($idOrArray)` | An upload: `name`, `size`, `type`, `path()`, `contents()`, `store()`, `delete()` |
| `Unscripted\UiServer\Components::test('name')` | A component for [tests](testing.md) |
| `Route::unscripted($uri, 'name', $options)` | A page that's one component. Options: `title`, `layout` |
| `Unscripted\Ui\ServerError` | A failed call: `getMessage()`, `status`, `errors` |
